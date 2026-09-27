<?php

namespace Espo\Custom\Tools\Consent;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Entities\User;
use PDO;
use stdClass;

final class CookieConsentService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Acl $acl,
        private Config $config,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $banner = $this->ensureBanner($context);
        $this->ensureCategories($context, (string) $banner['id']);

        $count = $this->entityManager->getPDO()->prepare(
            'SELECT COUNT(*) AS total, SUM(occurred_at >= DATE_SUB(NOW(6), INTERVAL 30 DAY)) AS recent ' .
            'FROM nexa_cookie_receipt WHERE tenant_id=? AND service_id=? AND banner_id=?'
        );
        $count->execute([$context->tenantId, $context->serviceId, $banner['id']]);
        $counts = $count->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'banner' => $this->bannerPayload($context, $banner, true),
            'summary' => [
                'totalReceipts' => (int) ($counts['total'] ?? 0),
                'last30Days' => (int) ($counts['recent'] ?? 0),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $data): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $banner = $this->ensureBanner($context);
        $name = $this->text($data->name ?? null, 120, 'Enter a banner name.');
        $heading = $this->text($data->heading ?? null, 160, 'Enter a banner heading.');
        $message = $this->text($data->message ?? null, 1000, 'Enter a clear cookie notice.');
        $version = $this->text($data->policyVersion ?? null, 40, 'Enter a policy version.');
        $locale = strtolower($this->text($data->locale ?? 'en', 12, 'Enter a locale.'));
        $integrationMode = strtolower(trim((string) ($data->integrationMode ?? 'managed')));
        $regionMode = strtolower(trim((string) ($data->regionMode ?? 'global')));
        $position = strtolower(trim((string) ($data->position ?? 'bottom')));
        if (!in_array($integrationMode, ['managed', 'existing_banner'], true)) throw new BadRequest('Select a valid cookie integration mode.');
        if (!in_array($regionMode, ['global', 'eu_uk', 'custom'], true)) throw new BadRequest('Select a valid regional mode.');
        if (!in_array($position, ['bottom', 'bottom_left', 'bottom_right'], true)) throw new BadRequest('Select a valid banner position.');
        $privacyUrl = trim((string) ($data->privacyNoticeUrl ?? '')) ?: null;
        if ($privacyUrl !== null && filter_var($privacyUrl, FILTER_VALIDATE_URL) === false) throw new BadRequest('Enter a valid privacy notice URL.');
        $regions = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(substr(trim((string) $value), 0, 3)),
            is_array($data->regions ?? null) ? $data->regions : []
        ))));
        if ($regionMode === 'custom' && $regions === []) throw new BadRequest('Add at least one country or region code.');
        $colors = [
            $this->color($data->primaryColor ?? '#087F6D'),
            $this->color($data->backgroundColor ?? '#FFFFFF'),
            $this->color($data->textColor ?? '#172B26'),
        ];
        $published = (bool) ($data->isPublished ?? false);

        $categories = is_array($data->categories ?? null) ? $data->categories : [];
        if ($categories === []) throw new BadRequest('Keep at least one cookie category.');
        $normalized = [];
        foreach ($categories as $index => $category) {
            $category = is_object($category) ? $category : (object) $category;
            $key = strtolower(preg_replace('/[^a-z0-9]+/', '_', trim((string) ($category->key ?? ''))) ?? '');
            $key = trim($key, '_');
            if ($key === '' || strlen($key) > 64) throw new BadRequest('Each cookie category needs a valid name.');
            if (isset($normalized[$key])) throw new BadRequest('Cookie category names must be unique.');
            $normalized[$key] = [
                'key' => $key,
                'name' => $this->text($category->name ?? null, 120, 'Each cookie category needs a name.'),
                'description' => mb_substr(trim((string) ($category->description ?? '')), 0, 500) ?: null,
                'isEssential' => (bool) ($category->isEssential ?? false),
                'defaultEnabled' => (bool) ($category->isEssential ?? false) || (bool) ($category->defaultEnabled ?? false),
                'position' => ($index + 1) * 10,
            ];
        }
        if (!array_filter($normalized, static fn (array $item): bool => $item['isEssential'])) {
            throw new BadRequest('Keep one required category for essential cookies.');
        }

        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $update = $pdo->prepare(
                'UPDATE nexa_cookie_banner SET name=?,policy_version=?,locale=?,integration_mode=?,region_mode=?,regions_json=?,position=?,' .
                'privacy_notice_url=?,heading=?,message=?,primary_color=?,background_color=?,text_color=?,show_reject=?,' .
                'is_published=?,published_at=IF(?=1,COALESCE(published_at,NOW(6)),NULL),modified_by_id=? ' .
                'WHERE id=? AND tenant_id=? AND service_id=?'
            );
            $update->execute([
                $name, $version, $locale, $integrationMode, $regionMode, json_encode($regions, JSON_THROW_ON_ERROR), $position,
                $privacyUrl, $heading, $message, ...$colors, (int) ($data->showReject ?? true),
                (int) $published, (int) $published, $this->user->getId(), $banner['id'], $context->tenantId, $context->serviceId,
            ]);
            $existing = $pdo->prepare('SELECT id,category_key FROM nexa_cookie_category WHERE tenant_id=? AND service_id=? AND banner_id=?');
            $existing->execute([$context->tenantId, $context->serviceId, $banner['id']]);
            $ids = [];
            foreach ($existing->fetchAll(PDO::FETCH_ASSOC) as $row) $ids[$row['category_key']] = $row['id'];
            foreach ($normalized as $key => $category) {
                $id = $ids[$key] ?? $this->uuid();
                $statement = $pdo->prepare(
                    'INSERT INTO nexa_cookie_category (id,tenant_id,service_id,banner_id,category_key,name,description,is_essential,default_enabled,position,is_active) ' .
                    'VALUES (?,?,?,?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),' .
                    'is_essential=VALUES(is_essential),default_enabled=VALUES(default_enabled),position=VALUES(position),is_active=1'
                );
                $statement->execute([$id, $context->tenantId, $context->serviceId, $banner['id'], $key, $category['name'], $category['description'], (int) $category['isEssential'], (int) $category['defaultEnabled'], $category['position']]);
            }
            $keys = array_keys($normalized);
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $archive = $pdo->prepare("UPDATE nexa_cookie_category SET is_active=0 WHERE tenant_id=? AND service_id=? AND banner_id=? AND category_key NOT IN ($placeholders)");
            $archive->execute([$context->tenantId, $context->serviceId, $banner['id'], ...$keys]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->getWorkspace();
    }

    /** @return array<string, mixed> */
    public function getPublicConfig(string $publicKey, ?string $regionCode = null): array
    {
        $banner = $this->publicBanner($publicKey);
        $payload = $this->bannerPayload(new TenantContext($banner['tenant_id'], 'public', 'cookie-public', '', $banner['service_id']), $banner, false);
        $regionCode = strtoupper(substr(trim((string) $regionCode), 0, 3));
        $payload['regionCode'] = $regionCode ?: null;
        $payload['shouldDisplay'] = $this->shouldDisplay($banner, $regionCode);
        return $payload;
    }

    /** @return array<string, mixed> */
    public function recordReceipt(stdClass $data): array
    {
        $publicKey = trim((string) ($data->publicKey ?? ''));
        $banner = $this->publicBanner($publicKey);
        $receiptKey = $this->uuidValue($data->receiptKey ?? null, 'receipt');
        $visitorId = $this->uuidValue($data->visitorId ?? null, 'visitor');
        $choice = strtolower(trim((string) ($data->choice ?? '')));
        if (!in_array($choice, ['accept_all', 'reject_optional', 'custom'], true)) throw new BadRequest('Select valid cookie preferences.');
        $rawCategories = $data->categories ?? [];
        if (is_string($rawCategories)) {
            $decoded = json_decode($rawCategories, true);
            $rawCategories = is_array($decoded) ? $decoded : [];
        }
        $categories = is_object($rawCategories) ? get_object_vars($rawCategories) : (array) $rawCategories;
        $config = $this->bannerPayload(new TenantContext($banner['tenant_id'], 'public', 'cookie-public', '', $banner['service_id']), $banner, false);
        $allowed = [];
        foreach ($config['categories'] as $category) {
            $allowed[$category['key']] = $category['isEssential'] ? true : (bool) ($categories[$category['key']] ?? false);
        }
        $pageUrl = $this->url($data->pageUrl ?? null);
        $referrerUrl = $this->url($data->referrerUrl ?? null);
        $agent = mb_substr(trim((string) ($data->userAgent ?? '')), 0, 1000);
        $hash = hash('sha256', json_encode([$config['policyVersion'], $config['categories']], JSON_THROW_ON_ERROR));
        $id = $this->uuid();
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT IGNORE INTO nexa_cookie_receipt (id,tenant_id,service_id,banner_id,receipt_key,visitor_id,choice,policy_version,' .
            'categories_json,configuration_hash,page_url,referrer_url,locale,region_code,global_privacy_control,user_agent_hash) ' .
            'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $id, $banner['tenant_id'], $banner['service_id'], $banner['id'], $receiptKey, $visitorId, $choice,
            $banner['policy_version'], json_encode($allowed, JSON_THROW_ON_ERROR), $hash, $pageUrl, $referrerUrl,
            mb_substr(trim((string) ($data->locale ?? '')), 0, 12) ?: null,
            mb_substr(strtoupper(trim((string) ($data->regionCode ?? ''))), 0, 12) ?: null,
            (int) ($data->globalPrivacyControl ?? false), $agent === '' ? null : hash('sha256', $agent),
        ]);
        return ['success' => true, 'receiptId' => $statement->rowCount() ? $id : $receiptKey];
    }

    /** @return array<string, mixed> */
    private function ensureBanner(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_cookie_banner WHERE tenant_id=? AND service_id=? LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId]);
        $banner = $statement->fetch(PDO::FETCH_ASSOC);
        if ($banner) return $banner;
        $id = $this->uuid();
        $insert = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_cookie_banner (id,tenant_id,service_id,public_key,message,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?)'
        );
        $insert->execute([$id, $context->tenantId, $context->serviceId, bin2hex(random_bytes(24)), 'We use cookies to operate this website and, with your permission, understand and improve your experience.', $this->user->getId(), $this->user->getId()]);
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetch(PDO::FETCH_ASSOC);
    }

    private function ensureCategories(TenantContext $context, string $bannerId): void
    {
        $count = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM nexa_cookie_category WHERE tenant_id=? AND service_id=? AND banner_id=?');
        $count->execute([$context->tenantId, $context->serviceId, $bannerId]);
        if ((int) $count->fetchColumn() > 0) return;
        $defaults = [
            ['necessary', 'Necessary', 'Required for security, authentication and core website operation.', 1, 1],
            ['preferences', 'Preferences', 'Remembers choices that personalize the website.', 0, 0],
            ['analytics', 'Analytics', 'Helps understand website usage and performance.', 0, 0],
            ['advertising', 'Advertising', 'Supports relevant advertising and campaign measurement.', 0, 0],
        ];
        foreach ($defaults as $index => $item) {
            $insert = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_cookie_category (id,tenant_id,service_id,banner_id,category_key,name,description,is_essential,default_enabled,position) VALUES (?,?,?,?,?,?,?,?,?,?)');
            $insert->execute([$this->uuid(), $context->tenantId, $context->serviceId, $bannerId, ...$item, ($index + 1) * 10]);
        }
    }

    /** @return array<string, mixed> */
    private function publicBanner(string $publicKey): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $publicKey)) throw new BadRequest('Cookie banner key is invalid.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_cookie_banner WHERE public_key=? AND is_published=1 LIMIT 1');
        $statement->execute([$publicKey]);
        $banner = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$banner) throw new BadRequest('Cookie banner is not published.');
        return $banner;
    }

    /** @return array<string, mixed> */
    private function bannerPayload(TenantContext $context, array $banner, bool $admin): array
    {
        $categories = $this->entityManager->getPDO()->prepare('SELECT category_key,name,description,is_essential,default_enabled,position FROM nexa_cookie_category WHERE tenant_id=? AND service_id=? AND banner_id=? AND is_active=1 ORDER BY position,name');
        $categories->execute([$context->tenantId, $context->serviceId, $banner['id']]);
        $payload = [
            'publicKey' => $banner['public_key'], 'name' => $banner['name'], 'policyVersion' => $banner['policy_version'],
            'locale' => $banner['locale'], 'integrationMode' => $banner['integration_mode'] ?? 'managed', 'regionMode' => $banner['region_mode'],
            'regions' => json_decode((string) ($banner['regions_json'] ?? '[]'), true) ?: [], 'position' => $banner['position'],
            'privacyNoticeUrl' => $banner['privacy_notice_url'], 'heading' => $banner['heading'], 'message' => $banner['message'],
            'primaryColor' => $banner['primary_color'], 'backgroundColor' => $banner['background_color'], 'textColor' => $banner['text_color'],
            'showReject' => (bool) $banner['show_reject'], 'isPublished' => (bool) $banner['is_published'],
            'categories' => array_map(static fn (array $row): array => [
                'key' => $row['category_key'], 'name' => $row['name'], 'description' => $row['description'],
                'isEssential' => (bool) $row['is_essential'], 'defaultEnabled' => (bool) $row['default_enabled'],
            ], $categories->fetchAll(PDO::FETCH_ASSOC)),
        ];
        if ($admin) $payload['embedCode'] = '<script src="' . $this->applicationBaseUrl() . '/client/custom/nexa-cookie-consent.js" data-nexa-cookie-key="' . $banner['public_key'] . '" data-nexa-cookie-mode="' . ($banner['integration_mode'] ?? 'managed') . '" defer></script>';
        return $payload;
    }

    private function applicationBaseUrl(): string
    {
        return rtrim((string) $this->config->get('siteUrl', ''), '/');
    }

    private function shouldDisplay(array $banner, string $regionCode): bool
    {
        if ($banner['region_mode'] === 'global' || $regionCode === '') return true;
        $regions = json_decode((string) ($banner['regions_json'] ?? '[]'), true) ?: [];
        if ($banner['region_mode'] === 'custom') return in_array($regionCode, $regions, true);
        return in_array($regionCode, [
            'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT','LV','LT','LU','MT',
            'NL','PL','PT','RO','SK','SI','ES','SE','GB','IS','LI','NO',
        ], true);
    }

    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin() || !$this->acl->checkScope('Contact')) throw new Forbidden('Tenant administrator access is required.');
    }

    private function text(mixed $value, int $max, string $message): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > $max) throw new BadRequest($message);
        return $value;
    }

    private function color(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));
        if (!preg_match('/^#[0-9A-F]{6}$/', $value)) throw new BadRequest('Select valid banner colours.');
        return $value;
    }

    private function url(mixed $value): ?string
    {
        $value = mb_substr(trim((string) $value), 0, 1000);
        return $value !== '' && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    private function uuidValue(mixed $value, string $label): string
    {
        $value = strtolower(trim((string) $value));
        if (!preg_match('/^[a-f0-9-]{36}$/', $value)) throw new BadRequest("Cookie $label identifier is invalid.");
        return $value;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
