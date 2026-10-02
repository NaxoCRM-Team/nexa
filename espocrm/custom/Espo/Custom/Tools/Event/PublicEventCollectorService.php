<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use PDO;
use stdClass;

/** Origin-bound, consent-aware public adapter for the canonical event service. */
final class PublicEventCollectorService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private PublicRequestLimiter $publicRequestLimiter,
        private EventContract $eventContract,
        private BehaviorEventService $behaviorEventService,
    ) {}

    /** @return array<string, mixed> */
    public function collect(string $key, string $origin, stdClass $input, bool $globalPrivacyControl): array
    {
        $source = $this->source($key, $origin);
        $encoded = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 98304) {
            throw new BadRequest('The event payload is too large.');
        }
        foreach (['contactId', 'accountId', 'identityEvidence'] as $trustedField) {
            if (property_exists($input, $trustedField)) {
                throw new Forbidden('Public events cannot assign trusted CRM identities.');
            }
        }
        $visitorKey = trim((string) ($input->visitorKey ?? ''));
        if ($visitorKey === '' || mb_strlen($visitorKey) > 500) {
            throw new BadRequest('Provide a visitor identifier.');
        }
        $pageUrl = trim((string) ($input->pageUrl ?? ''));
        if ($pageUrl !== '' && self::normalizeOrigin($pageUrl) !== $origin) {
            throw new Forbidden('The event page does not match the approved website origin.');
        }

        $input->source = $this->eventSource((string) $source['id']);
        $classification = $this->eventContract->classification($input->eventType ?? null, $input->consentCategory ?? 'analytics');
        $category = $classification['consent'];
        $mode = (string) $source['integration_mode'];
        if ($mode === 'necessary_only' && $category !== 'necessary') {
            throw new Forbidden('This source only accepts events required for website operation.');
        }
        if ($globalPrivacyControl && $category === 'advertising') {
            throw new Forbidden('Advertising events are disabled by Global Privacy Control.');
        }
        if ($category !== 'necessary') {
            if ($mode === 'managed') {
                $input->consent = (object) $this->managedConsent($source, $visitorKey, $category);
            } elseif ($mode === 'external') {
                $this->externalConsent($input, $category);
            }
        }
        $this->eventContract->normalize($input);

        $this->publicRequestLimiter->enforce(
            (string) $source['tenant_id'],
            (string) $source['service_id'],
            'event-collector:' . (string) $source['id'],
            120,
            60,
        );
        $context = new TenantContext(
            (string) $source['tenant_id'],
            'public',
            'website-event-collector',
            '',
            (string) $source['service_id'],
        );
        return $this->tenantContextStore->runWith($context, fn (): array => $this->behaviorEventService->ingest($input));
    }

    /** Validates a preflight and returns the exact origin to echo. */
    public function authorize(string $key, string $origin): string
    {
        $this->source($key, $origin);
        return $origin;
    }

    public static function normalizeOrigin(string $value): string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '*')) {
            throw new BadRequest('Enter a complete website origin without wildcards.');
        }
        $parts = parse_url($value);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        if ($host === '' || ($scheme !== 'https' && !($scheme === 'http' && $local)) || isset($parts['user']) || isset($parts['pass'])) {
            throw new BadRequest('Use an HTTPS website origin. HTTP is allowed only for local development.');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    /** @return array<string, mixed> */
    private function source(string $key, string $origin): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $key)) {
            throw new BadRequest('The tracking source key is invalid.');
        }
        $origin = self::normalizeOrigin($origin);
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT * FROM nexa_tracking_source WHERE public_key=? AND status='active' LIMIT 1"
        );
        $statement->execute([$key]);
        $source = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$source) {
            throw new Forbidden('The tracking source is unavailable.');
        }
        $origins = json_decode((string) $source['allowed_origins_json'], true) ?: [];
        if (!in_array($origin, $origins, true)) {
            throw new Forbidden('This website origin is not approved for the tracking source.');
        }
        return $source;
    }

    /** @return array<string, string> */
    private function managedConsent(array $source, string $visitorKey, string $category): array
    {
        if (!preg_match('/^[0-9a-f-]{36}$/i', $visitorKey)) {
            throw new Forbidden('A recorded Nexa cookie preference is required for this event.');
        }
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT r.categories_json,r.global_privacy_control FROM nexa_cookie_receipt r ' .
            'INNER JOIN nexa_cookie_banner b ON b.id=r.banner_id AND b.tenant_id=r.tenant_id AND b.service_id=r.service_id ' .
            'WHERE r.tenant_id=? AND r.service_id=? AND r.visitor_id=? AND b.is_published=1 ORDER BY r.occurred_at DESC LIMIT 1'
        );
        $statement->execute([$source['tenant_id'], $source['service_id'], strtolower($visitorKey)]);
        $receipt = $statement->fetch(PDO::FETCH_ASSOC);
        $categories = $receipt ? (json_decode((string) $receipt['categories_json'], true) ?: []) : [];
        if (($categories[$category] ?? false) !== true || ($category === 'advertising' && (bool) ($receipt['global_privacy_control'] ?? false))) {
            throw new Forbidden(ucfirst($category) . ' consent has not been recorded for this visitor.');
        }
        return array_map(static fn (bool $granted): string => $granted ? 'granted' : 'denied', $categories);
    }

    private function externalConsent(stdClass $input, string $category): void
    {
        $consent = is_object($input->consent ?? null) ? $input->consent : new stdClass();
        $evidence = is_object($input->consentEvidence ?? null) ? $input->consentEvidence : null;
        if (($consent->{$category} ?? null) !== 'granted' || !$evidence) {
            throw new Forbidden('External consent evidence is required for this event.');
        }
        $policy = trim((string) ($evidence->policyVersion ?? ''));
        $provider = trim((string) ($evidence->provider ?? ''));
        if ($policy === '' || $provider === '' || mb_strlen($policy) > 40 || mb_strlen($provider) > 120) {
            throw new BadRequest('Enter the external consent provider and policy version.');
        }
        $consent->evidenceProvider = $provider;
        $consent->policyVersion = $policy;
        $input->consent = $consent;
    }

    private function eventSource(string $id): string
    {
        return 'web.' . str_replace('-', '', substr($id, 0, 24));
    }
}
