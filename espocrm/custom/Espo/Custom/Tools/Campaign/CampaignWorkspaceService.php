<?php

namespace Espo\Custom\Tools\Campaign;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Consent-aware campaign governance layered on EspoCRM's native Campaign model. */
final class CampaignWorkspaceService
{
    private const CHANNELS = ['email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'];
    private const TYPES = ['Email', 'Newsletter', 'Informational Email', 'Web', 'Television', 'Radio', 'Mail'];

    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $recordServices,
        private TenantContextStore $tenantContextStore,
        private Acl $acl,
        private User $user,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $this->ensureProfiles($context);
        return [
            'campaigns' => $this->campaigns($context),
            'segments' => $this->segments($context),
            'purposes' => $this->purposes($context),
            'channels' => array_map(static fn (string $id): array => ['id' => $id, 'name' => ucwords(str_replace('_', ' ', $id))], self::CHANNELS),
            'types' => self::TYPES,
            'permissions' => [
                'create' => $this->user->isAdmin() && $this->acl->checkScope('Campaign', Table::ACTION_CREATE),
                'edit' => $this->user->isAdmin() && $this->acl->checkScope('Campaign', Table::ACTION_EDIT),
                'activate' => $this->user->isAdmin() && $this->acl->checkScope('Campaign', Table::ACTION_EDIT),
            ],
            'sendingEnabled' => false,
            'sendingMessage' => 'Audience activation records eligible contacts only. Marketing delivery is configured in the Email Marketing workstream.',
        ];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $input, ?string $id = null): array
    {
        $context = $this->tenantContextStore->require();
        $data = $this->input($context, $input);
        $isNew = $id === null;
        $this->requireAccess($isNew ? Table::ACTION_CREATE : Table::ACTION_EDIT);
        $native = (object) ['name' => $data['name'], 'type' => $data['type'], 'status' => $this->nativeStatus($data['workflowStatus']), 'startDate' => $data['startDate'], 'endDate' => $data['endDate'], 'description' => $data['description']];
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            if ($isNew) {
                $campaign = $this->recordServices->get('Campaign')->create($native, CreateParams::create());
                $id = $campaign->getId();
                $version = 1;
                $statement = $pdo->prepare('INSERT INTO nexa_campaign_profile (campaign_id,tenant_id,service_id,workflow_status,channel,purpose_id,enrollment_mode,audience_ids_json,exclusion_ids_json,version_number,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                $statement->execute([$id, $context->tenantId, $context->serviceId, $data['workflowStatus'], $data['channel'], $data['purposeId'], $data['enrollmentMode'], $this->json($data['audienceIds']), $this->json($data['exclusionIds']), $version, $this->user->getId(), $this->user->getId()]);
            } else {
                $profile = $this->requireCampaign($context, (string) $id);
                $this->recordServices->get('Campaign')->update((string) $id, $native, UpdateParams::create());
                $version = (int) $profile['version_number'] + 1;
                $statement = $pdo->prepare('UPDATE nexa_campaign_profile SET workflow_status=?,channel=?,purpose_id=?,enrollment_mode=?,audience_ids_json=?,exclusion_ids_json=?,version_number=?,modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND campaign_id=?');
                $statement->execute([$data['workflowStatus'], $data['channel'], $data['purposeId'], $data['enrollmentMode'], $this->json($data['audienceIds']), $this->json($data['exclusionIds']), $version, $this->user->getId(), $context->tenantId, $context->serviceId, $id]);
            }
            $this->syncTargetLists($context, (string) $id, $data['audienceIds'], false);
            $this->syncTargetLists($context, (string) $id, $data['exclusionIds'], true);
            $this->insertVersion($context, (string) $id, $version, $data);
            $this->insertEvent($context, (string) $id, $isNew ? 'created' : 'updated', $version, null, ['configuration' => $data]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'created' => $isNew, 'version' => $version];
    }

    /** @return array<string, mixed> */
    public function preview(stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $data = $this->audienceInput($context, $input);
        return $this->evaluate($context, $data['audienceIds'], $data['exclusionIds'], $data['purposeId'], $data['channel']);
    }

    /** @return array<string, mixed> */
    public function enroll(string $id): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $profile = $this->requireCampaign($context, $id);
        $audienceIds = $this->decodeIds($profile['audience_ids_json']);
        $purposeId = trim((string) $profile['purpose_id']);
        if (!$audienceIds || $purposeId === '') throw new BadRequest('Configure an audience and consent purpose before activating this campaign.');
        $result = $this->evaluate($context, $audienceIds, $this->decodeIds($profile['exclusion_ids_json']), $purposeId, (string) $profile['channel']);
        $version = (int) $profile['version_number'];
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $candidateIds = array_column(array_merge($result['eligibleRecords'], $result['suppressedRecords']), 'id');
            $current = $pdo->prepare("SELECT id,contact_id FROM nexa_campaign_enrollment WHERE tenant_id=? AND service_id=? AND campaign_id=? AND status<>'exited'");
            $current->execute([$context->tenantId, $context->serviceId, $id]);
            $exit = $pdo->prepare("UPDATE nexa_campaign_enrollment SET status='exited',reason_code='left_audience',reason_json=JSON_ARRAY('left_audience'),exited_at=NOW(6),decided_by_id=?,modified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?");
            foreach ($current->fetchAll(PDO::FETCH_ASSOC) as $existing) {
                if (in_array($existing['contact_id'], $candidateIds, true)) continue;
                $exit->execute([$this->user->getId(), $existing['id'], $context->tenantId, $context->serviceId]);
                $this->insertEvent($context, $id, 'contact_exited', $version, (string) $existing['contact_id'], ['reasonCode' => 'left_audience']);
            }
            $find = $pdo->prepare('SELECT status,reason_code FROM nexa_campaign_enrollment WHERE tenant_id=? AND service_id=? AND campaign_id=? AND contact_id=?');
            $upsert = $pdo->prepare("INSERT INTO nexa_campaign_enrollment (id,tenant_id,service_id,campaign_id,contact_id,campaign_version,status,reason_code,reason_json,enrolled_at,decided_by_id) VALUES (?,?,?,?,?,?,?,?,?,IF(?='enrolled',NOW(6),NULL),?) ON DUPLICATE KEY UPDATE campaign_version=VALUES(campaign_version),status=VALUES(status),reason_code=VALUES(reason_code),reason_json=VALUES(reason_json),enrolled_at=IF(VALUES(status)='enrolled',COALESCE(enrolled_at,NOW(6)),enrolled_at),exited_at=NULL,decided_by_id=VALUES(decided_by_id),modified_at=NOW(6)");
            foreach (array_merge($result['eligibleRecords'], $result['suppressedRecords']) as $record) {
                $status = $record['eligible'] ? 'enrolled' : 'suppressed';
                $reason = $record['eligible'] ? 'eligible' : (string) $record['reasonCode'];
                $find->execute([$context->tenantId, $context->serviceId, $id, $record['id']]);
                $previous = $find->fetch(PDO::FETCH_ASSOC) ?: null;
                $upsert->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $record['id'], $version, $status, $reason, $this->json($record['reasons']), $status, $this->user->getId()]);
                if (!$previous || $previous['status'] !== $status || $previous['reason_code'] !== $reason) {
                    $this->insertEvent($context, $id, $status === 'enrolled' ? 'contact_enrolled' : 'contact_suppressed', $version, (string) $record['id'], ['reasonCode' => $reason, 'reasons' => $record['reasons']]);
                }
            }
            $update = $pdo->prepare("UPDATE nexa_campaign_profile SET workflow_status='active',matched_count=?,eligible_count=?,suppressed_count=?,last_evaluated_at=NOW(6),last_evaluated_by_id=?,modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND campaign_id=?");
            $update->execute([$result['matchedCount'], $result['eligibleCount'], $result['suppressedCount'], $this->user->getId(), $this->user->getId(), $context->tenantId, $context->serviceId, $id]);
            $this->recordServices->get('Campaign')->update($id, (object) ['status' => 'Active'], UpdateParams::create());
            $this->insertEvent($context, $id, 'audience_activated', $version, null, ['matchedCount' => $result['matchedCount'], 'eligibleCount' => $result['eligibleCount'], 'suppressedCount' => $result['suppressedCount'], 'reasonCounts' => $result['reasonCounts']]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'status' => 'active', 'matchedCount' => $result['matchedCount'], 'eligibleCount' => $result['eligibleCount'], 'suppressedCount' => $result['suppressedCount'], 'reasonCounts' => $result['reasonCounts']];
    }

    /** Re-evaluates active continuous campaigns inside the scheduler's tenant context. */
    public function recalculateContinuous(): void
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare("SELECT campaign_id FROM nexa_campaign_profile WHERE tenant_id=? AND service_id=? AND workflow_status='active' AND enrollment_mode='continuous'");
        $statement->execute([$context->tenantId, $context->serviceId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $campaignId) $this->enroll((string) $campaignId);
    }

    /** @return array<string, mixed> */
    public function changeStatus(string $id, stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $profile = $this->requireCampaign($context, $id);
        $status = strtolower(trim((string) ($input->status ?? '')));
        if (!in_array($status, ['paused', 'completed', 'archived'], true)) throw new BadRequest('Choose a valid campaign status.');
        $statement = $this->entityManager->getPDO()->prepare('UPDATE nexa_campaign_profile SET workflow_status=?,modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND campaign_id=?');
        $statement->execute([$status, $this->user->getId(), $context->tenantId, $context->serviceId, $id]);
        $this->recordServices->get('Campaign')->update($id, (object) ['status' => $this->nativeStatus($status)], UpdateParams::create());
        $this->insertEvent($context, $id, $status, (int) $profile['version_number'], null, []);
        return ['id' => $id, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function input(TenantContext $context, stdClass $input): array
    {
        $name = trim((string) ($input->name ?? ''));
        if ($name === '') throw new BadRequest('Campaign name is required.');
        $type = trim((string) ($input->type ?? 'Email'));
        if (!in_array($type, self::TYPES, true)) throw new BadRequest('Choose a valid campaign type.');
        $workflowStatus = strtolower(trim((string) ($input->workflowStatus ?? 'draft')));
        if (!in_array($workflowStatus, ['draft', 'active', 'paused', 'completed', 'archived'], true)) throw new BadRequest('Choose a valid campaign status.');
        return $this->audienceInput($context, $input) + [
            'name' => mb_substr($name, 0, 255), 'type' => $type, 'workflowStatus' => $workflowStatus,
            'enrollmentMode' => ($input->enrollmentMode ?? 'snapshot') === 'continuous' ? 'continuous' : 'snapshot',
            'startDate' => $this->date($input->startDate ?? null), 'endDate' => $this->date($input->endDate ?? null),
            'description' => trim((string) ($input->description ?? '')) ?: null,
        ];
    }

    /** @return array{audienceIds: string[], exclusionIds: string[], purposeId: string, channel: string} */
    private function audienceInput(TenantContext $context, stdClass $input): array
    {
        $audienceIds = $this->ids($input->audienceIds ?? []);
        $exclusionIds = $this->ids($input->exclusionIds ?? []);
        if (!$audienceIds) throw new BadRequest('Choose at least one audience segment.');
        $this->validateTargetLists($context, array_values(array_unique(array_merge($audienceIds, $exclusionIds))));
        $channel = strtolower(trim((string) ($input->channel ?? 'email')));
        if (!in_array($channel, self::CHANNELS, true)) throw new BadRequest('Choose a valid campaign channel.');
        $purposeId = trim((string) ($input->purposeId ?? ''));
        if ($purposeId === '') throw new BadRequest('Choose the consent purpose for this campaign.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) FROM nexa_consent_purpose WHERE id=? AND tenant_id=? AND service_id=? AND is_active=1');
        $statement->execute([$purposeId, $context->tenantId, $context->serviceId]);
        if ((int) $statement->fetchColumn() !== 1) throw new BadRequest('The selected consent purpose is not available in this workspace.');
        return ['audienceIds' => $audienceIds, 'exclusionIds' => $exclusionIds, 'purposeId' => $purposeId, 'channel' => $channel];
    }

    /** @return array<string, mixed> */
    private function evaluate(TenantContext $context, array $audienceIds, array $exclusionIds, string $purposeId, string $channel): array
    {
        $in = implode(',', array_fill(0, count($audienceIds), '?'));
        $exclusionSql = '';
        if ($exclusionIds) {
            $exclude = implode(',', array_fill(0, count($exclusionIds), '?'));
            $exclusionSql = " AND NOT EXISTS (SELECT 1 FROM contact_target_list x WHERE x.tenant_id=c.tenant_id AND x.service_id=c.service_id AND x.contact_id=c.id AND x.deleted=0 AND x.target_list_id IN ({$exclude}))";
        }
        $sql = "SELECT c.id,TRIM(CONCAT_WS(' ',c.first_name,c.last_name)) AS name,c.marketing_status,c.do_not_contact,c.do_not_contact_channels,MAX(ctl.opted_out) AS audience_opted_out,ea.name AS email FROM contact c INNER JOIN contact_target_list ctl ON ctl.contact_id=c.id AND ctl.tenant_id=c.tenant_id AND ctl.service_id=c.service_id AND ctl.deleted=0 AND ctl.target_list_id IN ({$in}) LEFT JOIN entity_email_address eea ON eea.entity_id=c.id AND eea.entity_type='Contact' AND eea.`primary`=1 AND eea.deleted=0 AND eea.tenant_id=c.tenant_id AND eea.service_id=c.service_id LEFT JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=c.tenant_id AND ea.service_id=c.service_id WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0{$exclusionSql} GROUP BY c.id,c.first_name,c.last_name,c.marketing_status,c.do_not_contact,c.do_not_contact_channels,ea.name ORDER BY c.last_name,c.first_name,c.id";
        $statement = $this->entityManager->getPDO()->prepare($sql);
        $statement->execute([...$audienceIds, $context->tenantId, $context->serviceId, ...$exclusionIds]);
        $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        $consent = $this->entityManager->getPDO()->prepare('SELECT status,expires_at FROM nexa_consent_state WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=? AND channel=? LIMIT 1');
        $eligible = []; $suppressed = []; $reasonCounts = [];
        foreach ($records as $record) {
            $reasons = [];
            if ((string) $record['marketing_status'] !== 'Marketing') $reasons[] = 'not_marketing_contact';
            if ((int) $record['do_not_contact'] === 1) $reasons[] = 'do_not_contact_all';
            $blockedChannels = array_filter(array_map('trim', explode(',', strtolower((string) ($record['do_not_contact_channels'] ?? '')))));
            if (in_array($channel, $blockedChannels, true)) $reasons[] = 'do_not_contact_channel';
            if ((int) $record['audience_opted_out'] === 1) $reasons[] = 'audience_opt_out';
            if ($channel === 'email' && trim((string) ($record['email'] ?? '')) === '') $reasons[] = 'missing_email';
            $consent->execute([$context->tenantId, $context->serviceId, $record['id'], $purposeId, $channel]);
            $state = $consent->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$state || $state['status'] !== 'granted') $reasons[] = 'consent_not_granted';
            elseif ($state['expires_at'] && strtotime((string) $state['expires_at']) <= time()) $reasons[] = 'consent_expired';
            $reasons = array_values(array_unique($reasons));
            foreach ($reasons as $reason) $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $normalized = ['id' => $record['id'], 'name' => $record['name'] ?: 'Unnamed contact', 'email' => $record['email'], 'eligible' => !$reasons, 'reasonCode' => $reasons[0] ?? 'eligible', 'reasons' => $reasons];
            if ($reasons) $suppressed[] = $normalized; else $eligible[] = $normalized;
        }
        ksort($reasonCounts);
        return ['matchedCount' => count($records), 'eligibleCount' => count($eligible), 'suppressedCount' => count($suppressed), 'eligibleRecords' => $eligible, 'suppressedRecords' => $suppressed, 'reasonCounts' => $reasonCounts, 'sample' => array_slice(array_merge($eligible, $suppressed), 0, 25)];
    }

    /** @return array<int, array<string, mixed>> */
    private function campaigns(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT c.id,c.name,c.type,c.start_date AS startDate,c.end_date AS endDate,c.description,p.workflow_status AS workflowStatus,p.channel,p.purpose_id AS purposeId,p.enrollment_mode AS enrollmentMode,p.audience_ids_json AS audienceIdsJson,p.exclusion_ids_json AS exclusionIdsJson,p.version_number AS version,p.matched_count AS matchedCount,p.eligible_count AS eligibleCount,p.suppressed_count AS suppressedCount,p.last_evaluated_at AS lastEvaluatedAt,c.modified_at AS modifiedAt,cp.name AS purposeName FROM campaign c INNER JOIN nexa_campaign_profile p ON p.campaign_id=c.id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id LEFT JOIN nexa_consent_purpose cp ON cp.id=p.purpose_id AND cp.tenant_id=p.tenant_id AND cp.service_id=p.service_id WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND p.workflow_status<>'archived' ORDER BY c.modified_at DESC,c.name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(function (array $row): array {
            $row['audienceIds'] = $this->decodeIds($row['audienceIdsJson']); $row['exclusionIds'] = $this->decodeIds($row['exclusionIdsJson']);
            unset($row['audienceIdsJson'], $row['exclusionIdsJson']);
            foreach (['version', 'matchedCount', 'eligibleCount', 'suppressedCount'] as $key) $row[$key] = (int) $row[$key];
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array<string, mixed>> */
    private function segments(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT t.id,t.name,t.description,d.segment_type AS type,d.member_count AS memberCount,d.eligible_count AS eligibleCount,d.suppressed_count AS suppressedCount FROM target_list t LEFT JOIN nexa_segment_definition d ON d.target_list_id=t.id AND d.tenant_id=t.tenant_id AND d.service_id=t.service_id WHERE t.tenant_id=? AND t.service_id=? AND t.deleted=0 AND (d.status IS NULL OR d.status='active') ORDER BY t.name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function purposes(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,purpose_key AS purposeKey,name,description,channels_json AS channelsJson,policy_version AS policyVersion FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=? AND is_active=1 ORDER BY position,name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array { $row['channels'] = json_decode((string) $row['channelsJson'], true) ?: []; unset($row['channelsJson']); return $row; }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    private function ensureProfiles(TenantContext $context): void
    {
        $purpose = $this->entityManager->getPDO()->prepare("SELECT id FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=? AND is_active=1 AND purpose_key='marketing_communications' LIMIT 1");
        $purpose->execute([$context->tenantId, $context->serviceId]);
        $purposeId = $purpose->fetchColumn() ?: null;
        $statement = $this->entityManager->getPDO()->prepare("INSERT IGNORE INTO nexa_campaign_profile (campaign_id,tenant_id,service_id,workflow_status,channel,purpose_id,enrollment_mode,audience_ids_json,exclusion_ids_json,created_by_id,modified_by_id) SELECT c.id,c.tenant_id,c.service_id,CASE c.status WHEN 'Active' THEN 'active' WHEN 'Complete' THEN 'completed' WHEN 'Inactive' THEN 'paused' ELSE 'draft' END,'email',?,'snapshot',COALESCE((SELECT JSON_ARRAYAGG(ctl.target_list_id) FROM campaign_target_list ctl WHERE ctl.campaign_id=c.id AND ctl.tenant_id=c.tenant_id AND ctl.service_id=c.service_id AND ctl.deleted=0),JSON_ARRAY()),COALESCE((SELECT JSON_ARRAYAGG(ctx.target_list_id) FROM campaign_target_list_excluding ctx WHERE ctx.campaign_id=c.id AND ctx.tenant_id=c.tenant_id AND ctx.service_id=c.service_id AND ctx.deleted=0),JSON_ARRAY()),c.created_by_id,c.modified_by_id FROM campaign c WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0");
        $statement->execute([$purposeId, $context->tenantId, $context->serviceId]);
    }

    /** @return array<string, mixed> */
    private function requireCampaign(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT p.* FROM nexa_campaign_profile p INNER JOIN campaign c ON c.id=p.campaign_id AND c.tenant_id=p.tenant_id AND c.service_id=p.service_id WHERE p.tenant_id=? AND p.service_id=? AND p.campaign_id=? AND c.deleted=0 LIMIT 1');
        $statement->execute([$context->tenantId, $context->serviceId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Campaign not found.');
        return $row;
    }

    private function validateTargetLists(TenantContext $context, array $ids): void
    {
        if (!$ids) return;
        $in = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->entityManager->getPDO()->prepare("SELECT COUNT(*) FROM target_list WHERE tenant_id=? AND service_id=? AND deleted=0 AND id IN ({$in})");
        $statement->execute([$context->tenantId, $context->serviceId, ...$ids]);
        if ((int) $statement->fetchColumn() !== count($ids)) throw new BadRequest('One or more audience segments are not available in this workspace.');
    }

    private function syncTargetLists(TenantContext $context, string $campaignId, array $ids, bool $excluding): void
    {
        $table = $excluding ? 'campaign_target_list_excluding' : 'campaign_target_list';
        $clear = $this->entityManager->getPDO()->prepare("UPDATE {$table} SET deleted=1 WHERE tenant_id=? AND service_id=? AND campaign_id=?");
        $clear->execute([$context->tenantId, $context->serviceId, $campaignId]);
        $link = $this->entityManager->getPDO()->prepare("INSERT INTO {$table} (campaign_id,target_list_id,deleted,tenant_id,service_id) VALUES (?,?,0,?,?) ON DUPLICATE KEY UPDATE deleted=0,tenant_id=VALUES(tenant_id),service_id=VALUES(service_id)");
        foreach ($ids as $targetListId) $link->execute([$campaignId, $targetListId, $context->tenantId, $context->serviceId]);
    }

    private function insertVersion(TenantContext $context, string $campaignId, int $version, array $configuration): void
    {
        $json = $this->json($configuration);
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_campaign_version (id,tenant_id,service_id,campaign_id,version_number,configuration_json,configuration_hash,created_by_id) VALUES (?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $campaignId, $version, $json, hash('sha256', $json), $this->user->getId()]);
    }

    private function insertEvent(TenantContext $context, string $campaignId, string $type, int $version, ?string $contactId, array $payload): void
    {
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_campaign_event (id,tenant_id,service_id,campaign_id,contact_id,event_type,campaign_version,payload_json,actor_user_id) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $campaignId, $contactId, $type, $version, $this->json($payload), $this->user->getId()]);
    }

    private function nativeStatus(string $status): string { return match ($status) { 'active' => 'Active', 'paused', 'archived' => 'Inactive', 'completed' => 'Complete', default => 'Planning' }; }
    private function requireAccess(string $action): void { if (!$this->acl->checkScope('Campaign', $action)) throw new Forbidden('Campaign access is not allowed.'); if ($action !== Table::ACTION_READ && !$this->user->isAdmin()) throw new Forbidden('Only tenant administrators can configure campaigns.'); }
    private function date(mixed $value): ?string { $value = trim((string) $value); if ($value === '') return null; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) throw new BadRequest('Enter dates in YYYY-MM-DD format.'); return $value; }
    /** @return string[] */ private function ids(mixed $value): array { if ($value instanceof stdClass) $value = (array) $value; if (!is_array($value)) return []; return array_values(array_unique(array_filter(array_map(static fn ($id): string => trim((string) $id), $value)))); }
    /** @return string[] */ private function decodeIds(mixed $value): array { return $this->ids(json_decode((string) $value, true) ?: []); }
    private function json(mixed $value): string { return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
}
