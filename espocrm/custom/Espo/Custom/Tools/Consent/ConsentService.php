<?php

namespace Espo\Custom\Tools\Consent;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Contact\ContactLifecycleService;
use Espo\Entities\User;
use Espo\ORM\Entity;
use PDO;
use stdClass;

/** Purpose-based consent governance layered on native Contact opt-out fields. */
final class ConsentService
{
    private const CHANNELS = ['email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'];
    private const STATUSES = ['granted', 'denied', 'withdrawn', 'not_required'];
    private const LEGAL_BASES = [
        'LegitimateInterestLead', 'LegitimateInterestCustomer', 'LegitimateInterestOther',
        'PerformanceOfContract', 'FreelyGivenConsent', 'NotApplicable',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Acl $acl,
        private ContactLifecycleService $contactLifecycleService,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $this->ensureDefaultPurposes($context);
        $pdo = $this->entityManager->getPDO();
        $summary = $pdo->prepare(
            "SELECT COUNT(*) AS total_contacts, SUM(c.marketing_status = 'Marketing') AS marketing_contacts, " .
            "SUM(c.do_not_contact = 1 OR c.marketing_status = 'Unsubscribed') AS suppressed, " .
            "SUM(c.marketing_status = 'Marketing' AND c.do_not_contact = 0 AND EXISTS (" .
                "SELECT 1 FROM nexa_consent_state s INNER JOIN nexa_consent_purpose p " .
                "ON p.id=s.purpose_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id " .
                "WHERE s.tenant_id=c.tenant_id AND s.service_id=c.service_id AND s.contact_id=c.id " .
                "AND p.purpose_key='marketing_communications' AND s.channel='email' " .
                "AND s.status='granted' AND (s.expires_at IS NULL OR s.expires_at > NOW(6))" .
            ")) AS eligible FROM contact c WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0"
        );
        $summary->execute([$context->tenantId, $context->serviceId]);
        $counts = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
        $marketing = (int) ($counts['marketing_contacts'] ?? 0);
        $eligible = (int) ($counts['eligible'] ?? 0);
        $suppressed = (int) ($counts['suppressed'] ?? 0);

        return [
            'summary' => [
                'totalContacts' => (int) ($counts['total_contacts'] ?? 0),
                'marketingContacts' => $marketing,
                'eligible' => $eligible,
                'reviewRequired' => max(0, $marketing - $eligible - $suppressed),
                'suppressed' => $suppressed,
            ],
            'purposes' => $this->purposes($context, false),
            'recentEvents' => $this->recentEvents($context),
            'channels' => self::CHANNELS,
            'legalBases' => self::LEGAL_BASES,
            'canManage' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function getContactConsent(string $contactId): array
    {
        $contact = $this->requireContact($contactId, Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $this->ensureDefaultPurposes($context);
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT s.channel,s.status,s.legal_basis,s.policy_version,s.effective_at,s.expires_at,' .
            'p.id AS purpose_id,p.purpose_key,p.name AS purpose_name FROM nexa_consent_state s ' .
            'INNER JOIN nexa_consent_purpose p ON p.id=s.purpose_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id ' .
            'WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? ORDER BY p.position,p.name,s.channel'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $contactId]);
        $summary = $this->entityManager->getPDO()->prepare(
            'SELECT marketing_status,do_not_contact,do_not_contact_channels FROM contact ' .
            'WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 LIMIT 1'
        );
        $summary->execute([$contactId, $context->tenantId, $context->serviceId]);
        $contactSummary = $summary->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'contact' => [
                'id' => $contact->getId(), 'name' => (string) $contact->get('name'),
                'emailAddress' => $contact->get('emailAddress'),
                'marketingStatus' => $contactSummary['marketing_status'] ?? $contact->get('marketingStatus'),
                'doNotContact' => (bool) ($contactSummary['do_not_contact'] ?? $contact->get('doNotContact')),
                'restrictedChannels' => array_values(array_filter(explode(',', (string) ($contactSummary['do_not_contact_channels'] ?? $contact->get('doNotContactChannels'))))),
            ],
            'states' => array_map(fn (array $row): array => $this->camelState($row), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'purposes' => $this->purposes($context, true),
        ];
    }

    /** @return array<string, mixed> */
    public function savePurpose(stdClass $data): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $this->ensureDefaultPurposes($context);
        $id = trim((string) ($data->id ?? ''));
        $name = trim((string) ($data->name ?? ''));
        $key = $this->purposeKey((string) ($data->purposeKey ?? $name));
        $description = $this->nullableText($data->description ?? null, 1000, 'Description');
        $legalBasis = $this->legalBasis($data->defaultLegalBasis ?? null, true);
        $channels = $this->channels($data->channels ?? null);
        $notice = $this->nullableText($data->privacyNoticeUrl ?? null, 500, 'Privacy notice URL');
        $version = trim((string) ($data->policyVersion ?? '1.0'));
        if ($name === '' || mb_strlen($name) > 120) throw new BadRequest('Enter a purpose name of 120 characters or fewer.');
        if ($version === '' || mb_strlen($version) > 40) throw new BadRequest('Enter a valid policy version.');
        if ($notice !== null && filter_var($notice, FILTER_VALIDATE_URL) === false) throw new BadRequest('Enter a valid privacy notice URL.');

        $pdo = $this->entityManager->getPDO();
        if ($id !== '') {
            $existing = $pdo->prepare('SELECT id,purpose_key,is_system FROM nexa_consent_purpose WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1');
            $existing->execute([$id, $context->tenantId, $context->serviceId]);
            $purpose = $existing->fetch(PDO::FETCH_ASSOC);
            if (!$purpose) throw new BadRequest('Consent purpose was not found.');
            if ((int) $purpose['is_system'] === 1) $key = (string) $purpose['purpose_key'];
            $statement = $pdo->prepare('UPDATE nexa_consent_purpose SET purpose_key=?,name=?,description=?,default_legal_basis=?,channels_json=?,privacy_notice_url=?,policy_version=?,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?');
            $statement->execute([$key, $name, $description, $legalBasis, json_encode($channels, JSON_THROW_ON_ERROR), $notice, $version, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        } else {
            $id = $this->uuid();
            $positionStatement = $pdo->prepare('SELECT COALESCE(MAX(position),0)+10 FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=?');
            $positionStatement->execute([$context->tenantId, $context->serviceId]);
            $position = (int) $positionStatement->fetchColumn();
            $statement = $pdo->prepare('INSERT INTO nexa_consent_purpose (id,tenant_id,service_id,purpose_key,name,description,default_legal_basis,channels_json,privacy_notice_url,policy_version,position,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
            try {
                $statement->execute([$id, $context->tenantId, $context->serviceId, $key, $name, $description, $legalBasis, json_encode($channels, JSON_THROW_ON_ERROR), $notice, $version, $position, $this->user->getId(), $this->user->getId()]);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') throw new BadRequest('A consent purpose with this name or key already exists.');
                throw $e;
            }
        }
        return ['purpose' => $this->purposeById($context, $id)];
    }

    /** @return array<string, mixed> */
    public function recordDecision(stdClass $data): array
    {
        $contactId = trim((string) ($data->contactId ?? ''));
        $contact = $this->requireContact($contactId, Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $this->ensureDefaultPurposes($context);
        $purposeId = trim((string) ($data->purposeId ?? ''));
        $purpose = $this->purposeById($context, $purposeId);
        if (!$purpose || !$purpose['isActive']) throw new BadRequest('Select an active consent purpose.');
        $channel = strtolower(trim((string) ($data->channel ?? '')));
        if (!in_array($channel, $purpose['channels'], true)) throw new BadRequest('Select a channel supported by this purpose.');
        $status = strtolower(trim((string) ($data->status ?? '')));
        if (!in_array($status, self::STATUSES, true)) throw new BadRequest('Select a valid consent decision.');
        $source = strtolower(trim((string) ($data->source ?? 'manual')));
        if (!in_array($source, ['manual', 'form', 'import', 'api', 'preference_center', 'system'], true)) throw new BadRequest('Select a valid evidence source.');
        $legalBasis = $this->legalBasis($data->legalBasis ?? $purpose['defaultLegalBasis'], false);
        $note = $this->nullableText($data->evidenceNote ?? null, 1000, 'Evidence note');
        if ($status === 'granted' && $source === 'manual' && $note === null) throw new BadRequest('Describe how consent was obtained.');
        $occurredAt = $this->dateTime($data->occurredAt ?? null) ?? gmdate('Y-m-d H:i:s');
        $expiresAt = $this->dateTime($data->expiresAt ?? null);
        if ($expiresAt !== null && $expiresAt < $occurredAt) throw new BadRequest('Consent expiry cannot be earlier than the decision.');
        $supersedesEventId = trim((string) ($data->correctsEventId ?? '')) ?: null;
        $eventId = $this->uuid();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if ($supersedesEventId !== null) {
                $original = $pdo->prepare('SELECT id,contact_id,purpose_id,channel,voided_at,superseded_by_event_id FROM nexa_consent_event WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1 FOR UPDATE');
                $original->execute([$supersedesEventId, $context->tenantId, $context->serviceId]);
                $originalRow = $original->fetch(PDO::FETCH_ASSOC);
                if (!$originalRow || $originalRow['contact_id'] !== $contactId || $originalRow['voided_at'] !== null || $originalRow['superseded_by_event_id'] !== null) {
                    throw new BadRequest('The consent decision can no longer be corrected.');
                }
                if ($originalRow['purpose_id'] !== $purposeId || $originalRow['channel'] !== $channel) {
                    throw new BadRequest('A correction must keep the original purpose and channel. Void it and record a new decision instead.');
                }
            }
            $event = $pdo->prepare('INSERT INTO nexa_consent_event (id,tenant_id,service_id,contact_id,purpose_id,channel,status,legal_basis,source,policy_version,privacy_notice_url,evidence_note,evidence_json,actor_type,actor_id,occurred_at,expires_at,supersedes_event_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $event->execute([$eventId, $context->tenantId, $context->serviceId, $contactId, $purposeId, $channel, $status, $legalBasis, $source, $purpose['policyVersion'], $purpose['privacyNoticeUrl'], $note, json_encode(['recordedFrom' => 'Nexa consent workspace'], JSON_THROW_ON_ERROR), 'user', $this->user->getId(), $occurredAt, $expiresAt, $supersedesEventId]);
            if ($supersedesEventId !== null) {
                $supersede = $pdo->prepare('UPDATE nexa_consent_event SET superseded_by_event_id=? WHERE id=? AND tenant_id=? AND service_id=?');
                $supersede->execute([$eventId, $supersedesEventId, $context->tenantId, $context->serviceId]);
            }
            $state = $pdo->prepare('INSERT INTO nexa_consent_state (tenant_id,service_id,contact_id,purpose_id,channel,status,legal_basis,policy_version,source_event_id,effective_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),legal_basis=VALUES(legal_basis),policy_version=VALUES(policy_version),source_event_id=VALUES(source_event_id),effective_at=VALUES(effective_at),expires_at=VALUES(expires_at)');
            $state->execute([$context->tenantId, $context->serviceId, $contactId, $purposeId, $channel, $status, $legalBasis, $purpose['policyVersion'], $eventId, $occurredAt, $expiresAt]);
            $this->synchronizeChannel($context, $contactId, $channel, $note);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return $this->getContactConsent($contactId);
    }

    /** @return array<string, mixed> */
    public function voidDecision(string $eventId, stdClass $data): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $reason = $this->nullableText($data->reason ?? null, 500, 'Void reason');
        if ($reason === null || mb_strlen($reason) < 5) throw new BadRequest('Explain why this consent decision should be voided.');
        if (preg_match('/^[a-f0-9-]{36}$/i', $eventId) !== 1) throw new BadRequest('Select a valid consent decision.');
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT * FROM nexa_consent_event WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1 FOR UPDATE');
            $statement->execute([$eventId, $context->tenantId, $context->serviceId]);
            $event = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$event || $event['voided_at'] !== null) throw new BadRequest('The consent decision is unavailable or already voided.');
            $this->requireContact((string) $event['contact_id'], Table::ACTION_EDIT);
            $void = $pdo->prepare('UPDATE nexa_consent_event SET voided_at=NOW(6),voided_by_id=?,void_reason=? WHERE id=? AND tenant_id=? AND service_id=?');
            $void->execute([$this->user->getId(), $reason, $eventId, $context->tenantId, $context->serviceId]);
            if ($event['supersedes_event_id']) {
                $restore = $pdo->prepare('UPDATE nexa_consent_event SET superseded_by_event_id=NULL WHERE id=? AND tenant_id=? AND service_id=? AND superseded_by_event_id=?');
                $restore->execute([$event['supersedes_event_id'], $context->tenantId, $context->serviceId, $eventId]);
            }
            $stateSource = $pdo->prepare('SELECT source_event_id FROM nexa_consent_state WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=? AND channel=? LIMIT 1');
            $stateSource->execute([$context->tenantId, $context->serviceId, $event['contact_id'], $event['purpose_id'], $event['channel']]);
            if ($stateSource->fetchColumn() === $eventId) {
                $previous = $pdo->prepare(
                    'SELECT * FROM nexa_consent_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=? AND channel=? ' .
                    'AND voided_at IS NULL AND superseded_by_event_id IS NULL AND id<>? ORDER BY occurred_at DESC,id DESC LIMIT 1'
                );
                $previous->execute([$context->tenantId, $context->serviceId, $event['contact_id'], $event['purpose_id'], $event['channel'], $eventId]);
                $row = $previous->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $state = $pdo->prepare('UPDATE nexa_consent_state SET status=?,legal_basis=?,policy_version=?,source_event_id=?,effective_at=?,expires_at=? WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=? AND channel=?');
                    $state->execute([$row['status'], $row['legal_basis'], $row['policy_version'], $row['id'], $row['occurred_at'], $row['expires_at'], $context->tenantId, $context->serviceId, $event['contact_id'], $event['purpose_id'], $event['channel']]);
                } else {
                    $delete = $pdo->prepare('DELETE FROM nexa_consent_state WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=? AND channel=?');
                    $delete->execute([$context->tenantId, $context->serviceId, $event['contact_id'], $event['purpose_id'], $event['channel']]);
                }
            }
            $this->synchronizeChannel($context, (string) $event['contact_id'], (string) $event['channel'], $reason);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return $this->getWorkspace();
    }

    private function synchronizeChannel(TenantContext $context, string $contactId, string $channel, ?string $note): void
    {
        $pdo = $this->entityManager->getPDO();
        $blockedQuery = $pdo->prepare(
            "SELECT COUNT(*) FROM nexa_consent_state WHERE tenant_id=? AND service_id=? AND contact_id=? AND channel=? " .
            "AND status IN ('denied','withdrawn') AND (expires_at IS NULL OR expires_at>NOW(6))"
        );
        $blockedQuery->execute([$context->tenantId, $context->serviceId, $contactId, $channel]);
        $shouldBlock = (int) $blockedQuery->fetchColumn() > 0;
        $contactQuery = $pdo->prepare('SELECT do_not_contact_channels FROM contact WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 LIMIT 1');
        $contactQuery->execute([$contactId, $context->tenantId, $context->serviceId]);
        $restricted = array_values(array_filter(explode(',', (string) $contactQuery->fetchColumn())));
        $isBlocked = in_array($channel, $restricted, true);
        if ($shouldBlock !== $isBlocked) {
            $this->contactLifecycleService->setCommunicationPreference(
                [$contactId],
                [$channel],
                $shouldBlock ? 'blocked' : 'allowed',
                $shouldBlock ? 'legal_compliance' : 'consent_restored',
                $note,
            );
        }
        if ($channel !== 'email') return;
        $marketingQuery = $pdo->prepare(
            "SELECT COUNT(*) FROM nexa_consent_state s INNER JOIN nexa_consent_purpose p " .
            "ON p.id=s.purpose_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id " .
            "WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? AND s.channel='email' " .
            "AND p.purpose_key='marketing_communications' AND s.status='granted' " .
            "AND (s.expires_at IS NULL OR s.expires_at>NOW(6))"
        );
        $marketingQuery->execute([$context->tenantId, $context->serviceId, $contactId]);
        $marketingStatus = $shouldBlock ? 'Unsubscribed' : ((int) $marketingQuery->fetchColumn() > 0 ? 'Marketing' : 'Non-Marketing');
        $marketing = $pdo->prepare('UPDATE contact SET marketing_status=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0');
        $marketing->execute([$marketingStatus, $contactId, $context->tenantId, $context->serviceId]);
    }

    private function ensureDefaultPurposes(TenantContext $context): void
    {
        $defaults = [
            ['marketing_communications', 'Marketing communications', 'Promotional information, offers and marketing updates.', 'FreelyGivenConsent', ['email', 'sms', 'whatsapp', 'phone', 'postal'], 10],
            ['sales_outreach', 'Sales outreach', 'Relevant one-to-one sales follow-up and relationship development.', 'LegitimateInterestLead', ['email', 'phone', 'linkedin'], 20],
            ['customer_service', 'Customer service', 'Operational messages needed to provide contracted service and support.', 'PerformanceOfContract', ['email', 'phone', 'sms', 'whatsapp', 'postal', 'live_chat'], 30],
        ];
        $statement = $this->entityManager->getPDO()->prepare('INSERT IGNORE INTO nexa_consent_purpose (id,tenant_id,service_id,purpose_key,name,description,default_legal_basis,channels_json,policy_version,position,is_system,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,1,1)');
        foreach ($defaults as [$key, $name, $description, $basis, $channels, $position]) {
            $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $key, $name, $description, $basis, json_encode($channels, JSON_THROW_ON_ERROR), '1.0', $position]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function purposes(TenantContext $context, bool $activeOnly): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=?' . ($activeOnly ? ' AND is_active=1' : '') . ' ORDER BY position,name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(fn (array $row): array => $this->purposeRow($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    private function recentEvents(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT e.id,e.contact_id,e.purpose_id,e.channel,e.status,e.source,e.legal_basis,e.policy_version,e.occurred_at,e.expires_at,e.evidence_note,' .
            'e.supersedes_event_id,e.superseded_by_event_id,e.voided_at,e.void_reason,p.name AS purpose_name,c.first_name,c.last_name,c.profile_image_id,' .
            'u.first_name AS actor_first_name,u.last_name AS actor_last_name,u.user_name ' .
            'FROM nexa_consent_event e INNER JOIN nexa_consent_purpose p ON p.id=e.purpose_id AND p.tenant_id=e.tenant_id AND p.service_id=e.service_id ' .
            'INNER JOIN contact c ON c.id=e.contact_id AND c.tenant_id=e.tenant_id AND c.service_id=e.service_id ' .
            'LEFT JOIN user u ON u.id=e.actor_id AND u.tenant_id=e.tenant_id AND u.service_id=e.service_id ' .
            'WHERE e.tenant_id=? AND e.service_id=? AND c.deleted=0 ORDER BY e.occurred_at DESC,e.id DESC LIMIT 500'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array {
            $contactName = trim((string) $row['first_name'] . ' ' . (string) $row['last_name']);
            $actorName = trim((string) ($row['actor_first_name'] ?? '') . ' ' . (string) ($row['actor_last_name'] ?? ''));
            return [
                'id' => $row['id'], 'contactId' => $row['contact_id'], 'profileImageId' => $row['profile_image_id'],
                'contactName' => $contactName !== '' ? $contactName : 'Contact',
                'purposeId' => $row['purpose_id'], 'purposeName' => $row['purpose_name'], 'channel' => $row['channel'], 'status' => $row['status'],
                'source' => $row['source'], 'legalBasis' => $row['legal_basis'], 'policyVersion' => $row['policy_version'],
                'occurredAt' => $row['occurred_at'], 'expiresAt' => $row['expires_at'], 'evidenceNote' => $row['evidence_note'],
                'supersedesEventId' => $row['supersedes_event_id'], 'supersededByEventId' => $row['superseded_by_event_id'],
                'voidedAt' => $row['voided_at'], 'voidReason' => $row['void_reason'],
                'actorName' => $actorName !== '' ? $actorName : (string) ($row['user_name'] ?? 'System'),
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    private function purposeById(TenantContext $context, string $id): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_consent_purpose WHERE id=? AND tenant_id=? AND service_id=? LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->purposeRow($row) : null;
    }

    /** @return array<string, mixed> */
    private function purposeRow(array $row): array
    {
        return [
            'id' => $row['id'], 'purposeKey' => $row['purpose_key'], 'name' => $row['name'],
            'description' => $row['description'], 'defaultLegalBasis' => $row['default_legal_basis'],
            'channels' => json_decode((string) $row['channels_json'], true) ?: [],
            'privacyNoticeUrl' => $row['privacy_notice_url'], 'policyVersion' => $row['policy_version'],
            'position' => (int) $row['position'], 'isSystem' => (bool) $row['is_system'], 'isActive' => (bool) $row['is_active'],
        ];
    }

    /** @return array<string, mixed> */
    private function camelState(array $row): array
    {
        return [
            'purposeId' => $row['purpose_id'], 'purposeKey' => $row['purpose_key'], 'purposeName' => $row['purpose_name'],
            'channel' => $row['channel'], 'status' => $row['status'], 'legalBasis' => $row['legal_basis'],
            'policyVersion' => $row['policy_version'], 'effectiveAt' => $row['effective_at'], 'expiresAt' => $row['expires_at'],
        ];
    }

    private function requireContact(string $id, string $action): Entity
    {
        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) throw new BadRequest('Select a valid contact.');
        $contact = $this->entityManager->getRDBRepository('Contact')->getById($id);
        if (!$contact || !$this->acl->check($contact, $action)) throw new Forbidden('The selected contact is not accessible.');
        return $contact;
    }

    private function requireAdmin(): void
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Only a tenant administrator can manage consent governance.');
    }

    /** @return string[] */
    private function channels(mixed $value): array
    {
        if (!is_array($value)) throw new BadRequest('Select at least one communication channel.');
        $channels = array_values(array_unique(array_map(static fn ($item): string => strtolower(trim((string) $item)), $value)));
        if ($channels === [] || array_diff($channels, self::CHANNELS) !== []) throw new BadRequest('Select valid communication channels.');
        return $channels;
    }

    private function legalBasis(mixed $value, bool $nullable): ?string
    {
        $basis = trim((string) $value);
        if ($basis === '' && $nullable) return null;
        if (!in_array($basis, self::LEGAL_BASES, true)) throw new BadRequest('Select a valid legal basis.');
        return $basis;
    }

    private function purposeKey(string $value): string
    {
        $key = trim(strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $value)), '_');
        if ($key === '' || strlen($key) > 64) throw new BadRequest('Enter a valid purpose name.');
        return $key;
    }

    private function nullableText(mixed $value, int $max, string $label): ?string
    {
        $text = trim((string) $value);
        if ($text === '') return null;
        if (mb_strlen($text) > $max) throw new BadRequest("{$label} must be {$max} characters or fewer.");
        return $text;
    }

    private function dateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $time = strtotime($value);
        if ($time === false) throw new BadRequest('Enter a valid date and time.');
        return gmdate('Y-m-d H:i:s', $time);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
