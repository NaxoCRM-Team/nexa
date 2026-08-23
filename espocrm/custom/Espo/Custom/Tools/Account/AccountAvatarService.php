<?php

namespace Espo\Custom\Tools\Account;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Attachment;
use Espo\ORM\Entity;

final class AccountAvatarService
{
    private const SUCCESS_TTL = 2592000;
    private const FAILURE_TTL = 86400;
    private const MAX_IMAGE_BYTES = 262144;
    private const MAX_HTML_BYTES = 524288;
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private Acl $acl,
    ) {}

    /** @return array{available: bool, source?: string, mimeType?: string, data?: string} */
    public function get(string $accountId): array
    {
        $account = $this->entityManager->getRDBRepository('Account')->getById($accountId);

        if (!$account) {
            throw new NotFound('Account not found.');
        }
        if (!$this->acl->checkEntityRead($account)) {
            throw new Forbidden('Account avatar is not accessible.');
        }

        $logo = $this->readAttachment($account, (string) $account->get('companyLogoId'), 'companyLogo');
        if ($logo) {
            return ['available' => true, 'source' => 'logo'] + $logo;
        }

        $website = $this->normalizeWebsite((string) $account->get('website'));
        if (!$website) {
            return ['available' => false];
        }

        $host = strtolower((string) parse_url($website, PHP_URL_HOST));
        $cached = $this->readAttachment($account, (string) $account->get('faviconId'), 'favicon');
        $sameHost = hash_equals((string) $account->get('faviconSourceHost'), $host);
        $age = $this->cacheAge((string) $account->get('faviconFetchedAt'));
        if (!$sameHost) {
            $cached = null;
        }

        if ($sameHost && $cached && $age !== null && $age < self::SUCCESS_TTL) {
            return ['available' => true, 'source' => 'favicon'] + $cached;
        }
        if ($sameHost && !$cached && $age !== null && $age < self::FAILURE_TTL) {
            return ['available' => false];
        }

        $download = $this->discoverFavicon($website);
        if (!$download) {
            $oldId = (string) $account->get('faviconId');
            $keptId = $cached ? $oldId : null;
            $this->recordFetch($account, $host, $keptId);
            if (!$keptId) {
                $this->removeGeneratedFavicon($oldId, '');
            }

            return $cached ? ['available' => true, 'source' => 'favicon'] + $cached : ['available' => false];
        }

        $attachment = $this->storeFavicon($account, $download['contents'], $download['mimeType']);
        $oldId = (string) $account->get('faviconId');
        $this->recordFetch($account, $host, $attachment->getId());
        $this->removeGeneratedFavicon($oldId, $attachment->getId());

        return [
            'available' => true,
            'source' => 'favicon',
            'mimeType' => $download['mimeType'],
            'data' => base64_encode($download['contents']),
        ];
    }

    /** @return null|array{mimeType: string, data: string} */
    private function readAttachment(Entity $account, string $id, string $field): ?array
    {
        if ($id === '') {
            return null;
        }

        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById($id);
        if (!$attachment || $attachment->getRelatedType() !== 'Account' ||
            (string) $attachment->get('relatedId') !== $account->getId() ||
            $attachment->getTargetField() !== $field ||
            $attachment->getRole() !== Attachment::ROLE_ATTACHMENT) {
            return null;
        }

        $type = (string) $attachment->getType();
        if (!in_array($type, $this->allowedTypes(), true)) {
            return null;
        }

        try {
            return [
                'mimeType' => $type,
                'data' => base64_encode($this->fileStorageManager->getContents($attachment)),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeWebsite(string $website): ?string
    {
        $website = trim($website);
        if ($website === '') {
            return null;
        }
        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . $website;
        }

        $parts = parse_url($website);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (!in_array($scheme, ['http', 'https'], true) || !$this->validHost($host)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port . '/';
    }

    private function validHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host);
        }

        return strlen($host) <= 253 &&
            preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $host) === 1;
    }

    /** @return list<string> */
    private function resolvePublicIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->isPublicIp($host) ? [$host] : [];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }

        $ips = [];
        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ip === '' || !$this->isPublicIp($ip)) {
                return [];
            }
            $ips[] = $ip;
        }

        return array_values(array_unique($ips));
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /** @return null|array{contents: string, mimeType: string} */
    private function discoverFavicon(string $website): ?array
    {
        $direct = $this->fetch($this->resolveUrl($website, '/favicon.ico'), self::MAX_IMAGE_BYTES);
        if ($direct && ($type = $this->detectImageType($direct['body']))) {
            return ['contents' => $direct['body'], 'mimeType' => $type];
        }

        $home = $this->fetch($website, self::MAX_HTML_BYTES);
        if (!$home) {
            return null;
        }

        $iconUrl = $this->extractIconUrl($home['url'], $home['body']);
        if (!$iconUrl) {
            return null;
        }
        $icon = $this->fetch($iconUrl, self::MAX_IMAGE_BYTES);
        if (!$icon || !($type = $this->detectImageType($icon['body']))) {
            return null;
        }

        return ['contents' => $icon['body'], 'mimeType' => $type];
    }

    /** @return null|array{body: string, url: string} */
    private function fetch(string $url, int $limit): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
            if (!in_array($scheme, ['http', 'https'], true) || !$this->validHost($host)) {
                return null;
            }
            $ips = $this->resolvePublicIps($host);
            if (!$ips) {
                return null;
            }

            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
            $body = '';
            $headers = [];
            $tooLarge = false;
            $handle = curl_init($url);
            $pin = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
            curl_setopt_array($handle, [
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT_MS => 1500,
                CURLOPT_TIMEOUT_MS => 3500,
                CURLOPT_USERAGENT => 'NexaCRM-Favicon/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $host, $port, $pin)],
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                    if (str_starts_with($line, 'HTTP/')) {
                        $headers = [];
                    }
                    $position = strpos($line, ':');
                    if ($position !== false) {
                        $headers[strtolower(trim(substr($line, 0, $position)))] = trim(substr($line, $position + 1));
                    }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge, $limit): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            curl_close($handle);
            if ($ok === false || $tooLarge) {
                return null;
            }

            if ($status >= 300 && $status < 400 && isset($headers['location'])) {
                if ($redirect === self::MAX_REDIRECTS) {
                    return null;
                }
                $url = $this->resolveUrl($url, $headers['location']);
                continue;
            }

            return $status >= 200 && $status < 300 ? ['body' => $body, 'url' => $url] : null;
        }

        return null;
    }

    private function extractIconUrl(string $pageUrl, string $html): ?string
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML($html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }

        foreach ($document->getElementsByTagName('link') as $link) {
            $rel = strtolower((string) $link->getAttribute('rel'));
            $href = trim((string) $link->getAttribute('href'));
            if ($href !== '' && preg_match('/(?:^|\s)(?:shortcut\s+)?icon(?:\s|$)/', $rel)) {
                return $this->resolveUrl($pageUrl, $href);
            }
        }

        return null;
    }

    private function resolveUrl(string $base, string $reference): string
    {
        if (preg_match('~^https?://~i', $reference)) {
            return $reference;
        }

        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        if (str_starts_with($reference, '//')) {
            return $scheme . ':' . $reference;
        }
        if (str_starts_with($reference, '/')) {
            return $scheme . '://' . $host . $port . $reference;
        }

        $path = (string) ($parts['path'] ?? '/');

        return $scheme . '://' . $host . $port .
            rtrim(str_replace('\\', '/', dirname($path)), '/') . '/' . ltrim($reference, '/');
    }

    private function detectImageType(string $contents): ?string
    {
        if (str_starts_with($contents, "\x89PNG\r\n\x1a\n")) return 'image/png';
        if (str_starts_with($contents, "\xff\xd8\xff")) return 'image/jpeg';
        if (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a')) return 'image/gif';
        if (strlen($contents) >= 12 && substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') return 'image/webp';
        if (str_starts_with($contents, "\x00\x00\x01\x00")) return 'image/x-icon';

        return null;
    }

    private function storeFavicon(Entity $account, string $contents, string $type): Attachment
    {
        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getNew();
        $extension = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/x-icon' => 'ico',
        ][$type];
        $attachment->setName('account-favicon.' . $extension)
            ->setType($type)
            ->setRole(Attachment::ROLE_ATTACHMENT)
            ->setTargetField('favicon')
            ->setRelated($account)
            ->setContents($contents);
        $this->entityManager->saveEntity($attachment, [SaveOption::SILENT => true]);

        return $attachment;
    }

    private function recordFetch(Entity $account, string $host, ?string $attachmentId): void
    {
        $account->set([
            'faviconId' => $attachmentId,
            'faviconSourceHost' => $host,
            'faviconFetchedAt' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($account, [
            SaveOption::SILENT => true,
            SaveOption::SKIP_AUDITED => true,
            SaveOption::NO_STREAM => true,
            SaveOption::NO_NOTIFICATIONS => true,
        ]);
    }

    private function removeGeneratedFavicon(string $oldId, string $newId): void
    {
        if ($oldId === '' || $oldId === $newId) {
            return;
        }
        $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById($oldId);
        if ($attachment && $attachment->getTargetField() === 'favicon') {
            $this->entityManager->removeEntity($attachment, [SaveOption::SILENT => true]);
        }
    }

    private function cacheAge(string $timestamp): ?int
    {
        if ($timestamp === '') {
            return null;
        }
        try {
            return max(0, time() - (new DateTimeImmutable($timestamp . ' UTC'))->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function allowedTypes(): array
    {
        return ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/x-icon'];
    }
}
