<?php

namespace Espo\Custom\Tools\LandingPage;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Entities\Attachment;
use Espo\Entities\User;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use PDO;
use stdClass;

/** Tenant landing-page drafts and immutable publishing snapshots. */
final class LandingPageService
{
    private const BLOCK_TYPES = ['hero', 'text', 'image', 'form', 'cta', 'divider', 'features', 'stats', 'testimonial'];
    private const TEMPLATE_IMAGES = ['request-demo.jpg', 'consultation.jpg', 'event-registration.jpg', 'lead-magnet.jpg'];

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Config $config,
        private User $user,
        private PublicRequestLimiter $publicRequestLimiter,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        return ['pages' => $this->pages($context), 'forms' => $this->forms($context), 'assets' => $this->assets($context), 'templates' => $this->templates(), 'permissions' => ['create' => true, 'edit' => true, 'publish' => true, 'archive' => true]];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $input, ?string $id = null): array
    {
        $this->requireAdmin(); $context = $this->tenantContextStore->require();
        $configuration = $this->configuration($context, $input, false); $payload = $this->json($configuration);
        if ($id === null) {
            $id = $this->uuid();
            $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_landing_page (id,tenant_id,service_id,public_key,name,slug,locale,draft_configuration_json,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
            try { $statement->execute([$id, $context->tenantId, $context->serviceId, bin2hex(random_bytes(24)), $configuration['name'], $configuration['slug'], $configuration['locale'], $payload, $this->user->getId(), $this->user->getId()]); }
            catch (\PDOException $e) { if ((string) $e->getCode() === '23000') throw new BadRequest('A landing page already uses this URL path and language.'); throw $e; }
        } else {
            $this->requirePage($context, $id, true);
            $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_landing_page SET name=?,slug=?,locale=?,draft_configuration_json=?,status=IF(status='archived','draft',status),has_unpublished_changes=1,archived_at=NULL,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?");
            try { $statement->execute([$configuration['name'], $configuration['slug'], $configuration['locale'], $payload, $this->user->getId(), $id, $context->tenantId, $context->serviceId]); }
            catch (\PDOException $e) { if ((string) $e->getCode() === '23000') throw new BadRequest('A landing page already uses this URL path and language.'); throw $e; }
        }
        return ['id' => $id, 'saved' => true];
    }

    /** @return array<string, mixed> */
    public function publish(string $id): array
    {
        $this->requireAdmin(); $context = $this->tenantContextStore->require(); $page = $this->requirePage($context, $id, true);
        $raw = json_decode((string) $page['draft_configuration_json']);
        $configuration = $this->configuration($context, $raw instanceof stdClass ? $raw : new stdClass(), true); $snapshot = $this->json($configuration);
        $pdo = $this->entityManager->getPDO(); $owns = !$pdo->inTransaction(); if ($owns) $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT version_number FROM nexa_landing_page WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE'); $lock->execute([$id, $context->tenantId, $context->serviceId]); $version = ((int) $lock->fetchColumn()) + 1;
            $insert = $pdo->prepare('INSERT INTO nexa_landing_page_version (id,tenant_id,service_id,landing_page_id,version_number,configuration_json,configuration_hash,published_by_id) VALUES (?,?,?,?,?,?,?,?)'); $insert->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $version, $snapshot, hash('sha256', $snapshot), $this->user->getId()]);
            $update = $pdo->prepare("UPDATE nexa_landing_page SET status='published',version_number=?,has_unpublished_changes=0,published_at=NOW(6),archived_at=NULL,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?"); $update->execute([$version, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        return ['id' => $id, 'published' => true, 'version' => $version, 'url' => $this->publicUrl((string) $page['public_key'], $configuration['slug'])];
    }

    /** @return array<string, mixed> */
    public function archive(string $id): array
    {
        $this->requireAdmin(); $context = $this->tenantContextStore->require(); $this->requirePage($context, $id, true);
        $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_landing_page SET status='archived',archived_at=NOW(6),modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?"); $statement->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        return ['id' => $id, 'archived' => true];
    }

    /** @return array<string, mixed> */
    public function published(string $publicKey, string $slug): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $publicKey) || !$this->validSlug($slug)) throw new NotFound('Landing page not found.');
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.*,v.configuration_json FROM nexa_landing_page p INNER JOIN nexa_landing_page_version v ON v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.landing_page_id=p.id AND v.version_number=p.version_number WHERE p.public_key=? AND p.slug=? AND p.status='published' LIMIT 1");
        $statement->execute([$publicKey, $slug]); $page = $statement->fetch(PDO::FETCH_ASSOC); if (!$page) throw new NotFound('Landing page not found.');
        return ['page' => $page, 'configuration' => json_decode((string) $page['configuration_json'], true) ?: []];
    }

    /** @return array{attachment: Attachment, page: array<string, mixed>} */
    public function publishedAsset(string $publicKey, string $slug, string $assetId): array
    {
        $published = $this->published($publicKey, $slug); $referenced = false;
        $this->limitPublicRequest($published['page'], 'asset', 300, 600);
        foreach (($published['configuration']['blocks'] ?? []) as $block) if (($block['assetId'] ?? null) === $assetId) { $referenced = true; break; }
        if (!$referenced) throw new NotFound('Asset not found.'); $page = $published['page'];
        $statement = $this->entityManager->getPDO()->prepare("SELECT current_attachment_id FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=? AND status='active' AND access_scope='public' LIMIT 1"); $statement->execute([$assetId, $page['tenant_id'], $page['service_id']]); $attachmentId = $statement->fetchColumn();
        if (!$attachmentId) throw new NotFound('Asset not found.'); $attachment = $this->entityManager->getRDBRepositoryByClass(Attachment::class)->getById((string) $attachmentId);
        if (!$attachment || $attachment->isBeingUploaded()) throw new NotFound('Asset not found.'); return ['attachment' => $attachment, 'page' => $page];
    }

    public function recordEvent(string $publicKey, string $slug, string $type, ?string $target, ?string $referrer, ?string $userAgent): void
    {
        if (!in_array($type, ['view', 'click'], true)) throw new BadRequest('Invalid landing page event.');
        $published = $this->published($publicKey, $slug); $page = $published['page'];
        $this->limitPublicRequest($page, $type, $type === 'view' ? 300 : 120, 600);
        $eventKey = hash('sha256', implode('|', [$type, $page['id'], $target, microtime(true), bin2hex(random_bytes(8))]));
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_landing_page_event (id,tenant_id,service_id,landing_page_id,event_key,event_type,target_key,referrer,user_agent_hash,page_version) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $page['tenant_id'], $page['service_id'], $page['id'], $eventKey, $type, mb_substr(trim((string) $target), 0, 160) ?: null, $this->safeUrl($referrer), $userAgent ? hash('sha256', mb_substr($userAgent, 0, 1000)) : null, (int) $page['version_number']]);
    }

    public function signClickDestination(string $publicKey, string $slug, string $target, string $destination): string
    {
        return hash_hmac('sha256', implode('|', [$publicKey, $slug, $target, $destination]), $this->clickSecret());
    }

    public function verifyClickDestination(string $publicKey, string $slug, string $target, string $destination, string $signature): void
    {
        if ($signature === '' || !hash_equals($this->signClickDestination($publicKey, $slug, $target, $destination), $signature)) {
            throw new BadRequest('The landing page destination is invalid.');
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function pages(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.*,COALESCE(e.views,0) views,COALESCE(e.clicks,0) clicks FROM nexa_landing_page p LEFT JOIN (SELECT landing_page_id,tenant_id,service_id,SUM(event_type='view') views,SUM(event_type='click') clicks FROM nexa_landing_page_event GROUP BY landing_page_id,tenant_id,service_id) e ON e.landing_page_id=p.id AND e.tenant_id=p.tenant_id AND e.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? ORDER BY p.modified_at DESC"); $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'slug' => $row['slug'], 'locale' => $row['locale'], 'status' => $row['status'], 'version' => (int) $row['version_number'], 'hasUnpublishedChanges' => (bool) $row['has_unpublished_changes'], 'configuration' => json_decode((string) $row['draft_configuration_json'], true) ?: [], 'views' => (int) $row['views'], 'clicks' => (int) $row['clicks'], 'publishedAt' => $row['published_at'], 'modifiedAt' => $row['modified_at'], 'url' => $row['status'] === 'published' ? $this->publicUrl($row['public_key'], $row['slug']) : null], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    private function forms(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.lead_capture_id,p.draft_configuration_json,l.form_id FROM nexa_form_profile p INNER JOIN lead_capture l ON l.id=p.lead_capture_id AND l.tenant_id=p.tenant_id AND l.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.status='published' AND l.deleted=0 AND l.is_active=1 AND l.form_enabled=1 ORDER BY p.modified_at DESC"); $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array { $config = json_decode((string) $row['draft_configuration_json'], true) ?: []; return ['id' => $row['lead_capture_id'], 'name' => $config['name'] ?? 'Published form', 'publicId' => $row['form_id']]; }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    private function assets(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT id,display_name,alt_text,locale,access_scope FROM nexa_asset_profile WHERE tenant_id=? AND service_id=? AND status='active' AND access_scope='public' ORDER BY modified_at DESC LIMIT 500"); $statement->execute([$context->tenantId, $context->serviceId]); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function configuration(TenantContext $context, stdClass $input, bool $publishing): array
    {
        $name = $this->text($input->name ?? null, 160, 'Enter a landing page name.'); $slug = strtolower(trim((string) ($input->slug ?? '')));
        if (!$this->validSlug($slug)) throw new BadRequest('Use lowercase letters, numbers and hyphens for the page URL.');
        $locale = strtolower(trim((string) ($input->locale ?? 'en'))); if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $locale)) throw new BadRequest('Enter a valid page language.');
        $seoTitle = $this->text($input->seoTitle ?? $name, 70, 'Enter an SEO page title.'); $seoDescription = mb_substr(trim((string) ($input->seoDescription ?? '')), 0, 180);
        $canonicalUrl = trim((string) ($input->canonicalUrl ?? '')); if ($canonicalUrl !== '' && filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false) throw new BadRequest('Enter a valid canonical URL.');
        $colors = []; foreach (['primaryColor' => '#087d71', 'backgroundColor' => '#ffffff', 'textColor' => '#172f35'] as $key => $default) { $value = strtolower(trim((string) ($input->{$key} ?? $default))); $colors[$key] = preg_match('/^#[a-f0-9]{6}$/', $value) ? $value : $default; }
        $blocks = []; foreach (array_slice((array) ($input->blocks ?? []), 0, 100) as $position => $raw) $blocks[] = $this->block($context, is_object($raw) ? $raw : (object) $raw, $position, $publishing);
        if ($publishing && !$blocks) throw new BadRequest('Add at least one content block before publishing.');
        $sourceTemplate = trim((string) ($input->sourceTemplate ?? ''));
        if (!preg_match('/^[a-z0-9-]{0,64}$/', $sourceTemplate)) $sourceTemplate = '';
        return ['name' => $name, 'slug' => $slug, 'locale' => $locale, 'seoTitle' => $seoTitle, 'seoDescription' => $seoDescription, 'canonicalUrl' => $canonicalUrl ?: null, 'noIndex' => (bool) ($input->noIndex ?? false), 'sourceTemplate' => $sourceTemplate ?: null, ...$colors, 'blocks' => $blocks];
    }

    /** @return array<string, mixed> */
    private function block(TenantContext $context, stdClass $block, int $position, bool $publishing): array
    {
        $type = strtolower(trim((string) ($block->type ?? ''))); if (!in_array($type, self::BLOCK_TYPES, true)) throw new BadRequest('The page contains an unsupported content block.');
        $rawId = (string) ($block->id ?? ''); $id = preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $rawId) ? $rawId : 'block-' . ($position + 1) . '-' . substr(bin2hex(random_bytes(4)), 0, 8); $result = ['id' => $id, 'type' => $type];
        foreach (['eyebrow' => 100, 'heading' => 200, 'text' => 2000, 'caption' => 300, 'buttonLabel' => 80, 'formButtonLabel' => 80, 'altText' => 500] as $field => $max) $result[$field] = mb_substr(trim((string) ($block->{$field} ?? '')), 0, $max);
        $result['buttonUrl'] = $this->link($block->buttonUrl ?? null); $result['assetId'] = trim((string) ($block->assetId ?? '')) ?: null; $result['formId'] = trim((string) ($block->formId ?? '')) ?: null;
        $formMode = strtolower(trim((string) ($block->formMode ?? 'modal')));
        $result['formMode'] = in_array($formMode, ['modal', 'embedded', 'page'], true) ? $formMode : 'modal';
        $result['buttonNewTab'] = (bool) ($block->buttonNewTab ?? false);
        if ($type === 'form' && $result['formButtonLabel'] === '') $result['formButtonLabel'] = 'Open form';
        if ($type === 'form' && !$result['formId']) $result['formId'] = $this->resolveUnambiguousForm($context, $publishing);
        $templateImage = trim((string) ($block->templateImage ?? ''));
        $result['templateImage'] = in_array($templateImage, self::TEMPLATE_IMAGES, true) ? $templateImage : null;
        $result['items'] = [];
        foreach (array_slice((array) ($block->items ?? []), 0, 8) as $item) {
            $item = is_object($item) ? $item : (object) $item;
            $title = mb_substr(trim((string) ($item->title ?? '')), 0, 120);
            $text = mb_substr(trim((string) ($item->text ?? '')), 0, 500);
            $meta = mb_substr(trim((string) ($item->meta ?? '')), 0, 160);
            if ($title !== '' || $text !== '' || $meta !== '') $result['items'][] = ['title' => $title, 'text' => $text, 'meta' => $meta];
        }
        if ($result['assetId']) $this->requireAsset($context, $result['assetId'], $publishing); if ($result['formId']) $this->requireForm($context, $result['formId']);
        if ($publishing && $type === 'form' && !$result['formId']) throw new BadRequest('Choose a published form for each form block.');
        if ($type === 'image' && !$result['assetId'] && !$result['templateImage']) throw new BadRequest('Choose a public asset for each image block.'); return $result;
    }

    /** @return array<int, array<string, mixed>> */
    private function templates(): array { return LandingPageTemplateCatalog::get(rtrim((string) $this->config->get('siteUrl'), '/')); }

    private function requireAsset(TenantContext $context, string $id, bool $publishing): void { $sql = "SELECT 1 FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=? AND status='active'" . ($publishing ? " AND access_scope='public'" : '') . ' LIMIT 1'; $statement = $this->entityManager->getPDO()->prepare($sql); $statement->execute([$id, $context->tenantId, $context->serviceId]); if (!$statement->fetchColumn()) throw new BadRequest('A selected page asset is unavailable' . ($publishing ? ' or is not public.' : '.')); }
    private function requireForm(TenantContext $context, string $id): void { $statement = $this->entityManager->getPDO()->prepare("SELECT 1 FROM nexa_form_profile p INNER JOIN lead_capture l ON l.id=p.lead_capture_id AND l.tenant_id=p.tenant_id AND l.service_id=p.service_id WHERE p.lead_capture_id=? AND p.tenant_id=? AND p.service_id=? AND p.status='published' AND l.deleted=0 AND l.is_active=1 AND l.form_enabled=1 LIMIT 1"); $statement->execute([$id, $context->tenantId, $context->serviceId]); if (!$statement->fetchColumn()) throw new BadRequest('A selected form is not published in this workspace.'); }
    private function resolveUnambiguousForm(TenantContext $context, bool $publishing): ?string
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT p.lead_capture_id FROM nexa_form_profile p INNER JOIN lead_capture l ON l.id=p.lead_capture_id AND l.tenant_id=p.tenant_id AND l.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.status='published' AND l.deleted=0 AND l.is_active=1 AND l.form_enabled=1 ORDER BY p.modified_at DESC LIMIT 2");
        $statement->execute([$context->tenantId, $context->serviceId]);
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) === 1) return (string) $ids[0];
        if ($publishing && count($ids) === 0) throw new BadRequest('Publish a form before publishing a landing page that contains a form block.');
        return null;
    }
    /** @return array<string, mixed> */
    private function requirePage(TenantContext $context, string $id, bool $archived = false): array { if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) throw new BadRequest('Select a valid landing page.'); $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_landing_page WHERE id=? AND tenant_id=? AND service_id=?' . ($archived ? '' : " AND status<>'archived'") . ' LIMIT 1'); $statement->execute([$id, $context->tenantId, $context->serviceId]); $row = $statement->fetch(PDO::FETCH_ASSOC); if (!$row) throw new NotFound('The landing page is unavailable.'); return $row; }
    private function publicUrl(string $key, string $slug): string { return rtrim((string) $this->config->get('siteUrl'), '/') . '/p/' . rawurlencode($key) . '/' . rawurlencode($slug); }
    /** @param array<string, mixed> $page */
    private function limitPublicRequest(array $page, string $operation, int $limit, int $windowSeconds): void { $this->publicRequestLimiter->enforce((string) $page['tenant_id'], (string) $page['service_id'], 'landing-' . $operation . ':' . (string) $page['id'], $limit, $windowSeconds); }
    private function clickSecret(): string { $secret = (string) $this->config->get('hashSecretKey', ''); return $secret !== '' ? $secret : (string) $this->config->get('siteUrl', 'nexa'); }
    private function validSlug(string $slug): bool { return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,158}[a-z0-9])?$/', $slug) === 1; }
    private function link(mixed $value): ?string { $value = trim((string) ($value ?? '')); if ($value === '') return null; if (preg_match('/^#[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $value) || str_starts_with($value, '/') || filter_var($value, FILTER_VALIDATE_URL) !== false) return mb_substr($value, 0, 1000); throw new BadRequest('Enter a valid button URL or section anchor.'); }
    private function safeUrl(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value !== '' && filter_var($value, FILTER_VALIDATE_URL) !== false ? mb_substr($value, 0, 1000) : null; }
    private function text(mixed $value, int $max, string $error): string { $value = trim((string) ($value ?? '')); if ($value === '') throw new BadRequest($error); return mb_substr($value, 0, $max); }
    private function requireAdmin(): void { if (!$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can manage landing pages.'); }
    private function json(array $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    private function uuid(): string { $data = random_bytes(16); $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)); }
}
