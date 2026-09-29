<?php

namespace Espo\Custom\Tools\Segment;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Governed static and dynamic segments layered on native Target Lists. */
final class SegmentWorkspaceService
{
    private const FIELDS = [
        'lifecycleStage' => ['label' => 'Lifecycle stage', 'type' => 'text', 'expression' => 'c.lifecycle_stage'],
        'marketingStatus' => ['label' => 'Marketing status', 'type' => 'text', 'expression' => 'c.marketing_status'],
        'leadStatus' => ['label' => 'Lead status', 'type' => 'text', 'expression' => 'c.lead_status'],
        'leadScore' => ['label' => 'Lead score', 'type' => 'number', 'expression' => 'c.lead_score'],
        'source' => ['label' => 'Contact source', 'type' => 'text', 'expression' => 'c.source'],
        'country' => ['label' => 'Contact country', 'type' => 'text', 'expression' => 'c.address_country'],
        'city' => ['label' => 'Contact city', 'type' => 'text', 'expression' => 'c.address_city'],
        'createdAt' => ['label' => 'Contact created date', 'type' => 'date', 'expression' => 'c.created_at'],
        'lastWebsiteVisitAt' => ['label' => 'Last website visit', 'type' => 'date', 'expression' => 'c.last_website_visit_at'],
        'doNotContact' => ['label' => 'Do not contact', 'type' => 'boolean', 'expression' => 'c.do_not_contact'],
        'accountIndustry' => ['label' => 'Company industry', 'type' => 'text', 'expression' => 'a.industry'],
        'accountType' => ['label' => 'Company type', 'type' => 'text', 'expression' => 'a.type'],
        'accountCountry' => ['label' => 'Company country', 'type' => 'text', 'expression' => 'a.billing_address_country'],
        'accountLeadScore' => ['label' => 'Company lead score', 'type' => 'number', 'expression' => 'a.lead_score'],
        'accountLifecycleStage' => ['label' => 'Company lifecycle stage', 'type' => 'text', 'expression' => 'a.lifecycle_stage'],
    ];

    private const OPERATORS = [
        'equals', 'notEquals', 'contains', 'greaterThan', 'lessThan',
        'isEmpty', 'isNotEmpty', 'withinLastDays', 'beforeDays',
    ];

    private const TYPE_OPERATORS = [
        'text' => ['equals', 'notEquals', 'contains', 'isEmpty', 'isNotEmpty'],
        'number' => ['equals', 'notEquals', 'greaterThan', 'lessThan', 'isEmpty', 'isNotEmpty'],
        'date' => ['withinLastDays', 'beforeDays', 'isEmpty', 'isNotEmpty'],
        'boolean' => ['equals', 'notEquals'],
    ];

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
        $this->ensureDefinitions($context);
        $this->refreshStaticCounts($context);

        return [
            'segments' => $this->segments($context),
            'folders' => $this->folders($context),
            'recordTypes' => [['id' => 'Contact', 'name' => 'Contacts', 'description' => 'People stored in your CRM.']],
            'fieldCatalog' => array_map(
                static fn (string $key, array $field): array => ['id' => $key, 'name' => $field['label'], 'type' => $field['type'], 'operators' => self::TYPE_OPERATORS[$field['type']]],
                array_keys(self::FIELDS),
                array_values(self::FIELDS),
            ),
            'operators' => [
                ['id' => 'equals', 'name' => 'equals'], ['id' => 'notEquals', 'name' => 'does not equal'],
                ['id' => 'contains', 'name' => 'contains'], ['id' => 'greaterThan', 'name' => 'is greater than'],
                ['id' => 'lessThan', 'name' => 'is less than'], ['id' => 'isEmpty', 'name' => 'is empty'],
                ['id' => 'isNotEmpty', 'name' => 'is not empty'],
                ['id' => 'withinLastDays', 'name' => 'is within the last number of days'],
                ['id' => 'beforeDays', 'name' => 'is older than this number of days'],
            ],
            'permissions' => [
                'create' => $this->user->isAdmin() && $this->acl->checkScope('TargetList', Table::ACTION_CREATE),
                'edit' => $this->user->isAdmin() && $this->acl->checkScope('TargetList', Table::ACTION_EDIT),
                'delete' => $this->user->isAdmin() && $this->acl->checkScope('TargetList', Table::ACTION_DELETE),
            ],
            'behavioralRulesAvailable' => false,
            'behavioralRulesMessage' => 'Website, email and campaign behaviour rules become available when the Phase 5 event foundation is enabled.',
        ];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $input, ?string $id = null): array
    {
        $context = $this->tenantContextStore->require();
        $definition = $this->definitionInput($input);
        $isNew = $id === null;

        if ($id === null) {
            $this->requireAccess(Table::ACTION_CREATE);
            $targetList = $this->recordServices->get('TargetList')->create((object) [
                'name' => $definition['name'], 'description' => $definition['description'],
            ], CreateParams::create());
            $id = $targetList->getId();
            $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_segment_definition (target_list_id,tenant_id,service_id,segment_type,folder_name,access_level,match_mode,rules_json,exclusion_rules_json,snapshot_from_rules,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([$id, $context->tenantId, $context->serviceId, $definition['type'], $definition['folder'], $definition['accessLevel'], $definition['matchMode'], $this->json($definition['rules']), $this->json($definition['exclusionRules']), $definition['snapshotFromRules'] ? 1 : 0, $this->user->getId(), $this->user->getId()]);
            $version = 1;
        } else {
            $this->requireAccess(Table::ACTION_EDIT);
            $segment = $this->requireSegment($context, $id);
            $this->recordServices->get('TargetList')->update($id, (object) ['name' => $definition['name'], 'description' => $definition['description']], UpdateParams::create());
            $version = (int) $segment['version_number'] + 1;
            $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_segment_definition SET status='active',segment_type=?,folder_name=?,access_level=?,match_mode=?,rules_json=?,exclusion_rules_json=?,snapshot_from_rules=?,version_number=?,modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND target_list_id=?");
            $statement->execute([$definition['type'], $definition['folder'], $definition['accessLevel'], $definition['matchMode'], $this->json($definition['rules']), $this->json($definition['exclusionRules']), $definition['snapshotFromRules'] ? 1 : 0, $version, $this->user->getId(), $context->tenantId, $context->serviceId, $id]);
        }

        $this->insertVersion($context, $id, $version, $definition);
        if ($definition['type'] === 'dynamic' || ($isNew && $definition['snapshotFromRules'])) $this->recalculate($id, true);
        else $this->refreshCounts($context, $id);

        return ['id' => $id, 'created' => $isNew, 'version' => $version];
    }

    /** @return array<string, mixed> */
    public function preview(stdClass $input): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $matchMode = $this->matchMode($input->matchMode ?? null);
        $rules = $this->rules((array) ($input->rules ?? []), true);
        $exclusions = $this->rules((array) ($input->exclusionRules ?? []), false);
        return $this->evaluate($context, $matchMode, $rules, $exclusions, true);
    }

    /** @return array<string, mixed> */
    public function recalculate(string $id, bool $allowStaticSnapshot = false): array
    {
        $this->requireAccess(Table::ACTION_EDIT);
        $context = $this->tenantContextStore->require();
        $segment = $this->requireSegment($context, $id);
        if ($segment['segment_type'] !== 'dynamic' && !$allowStaticSnapshot) throw new BadRequest('Only active segments can be recalculated.');
        $rules = $this->rules((array) json_decode((string) $segment['rules_json'], true), true);
        $exclusions = $this->rules((array) json_decode((string) $segment['exclusion_rules_json'], true), false);
        $result = $this->evaluate($context, (string) $segment['match_mode'], $rules, $exclusions, false);
        $newIds = array_column($result['records'], 'id');
        $current = $this->currentMemberIds($context, $id);
        $entered = array_values(array_diff($newIds, $current));
        $exited = array_values(array_diff($current, $newIds));
        $runId = $this->uuid();
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $run = $pdo->prepare('INSERT INTO nexa_segment_run (id,tenant_id,service_id,target_list_id,version_number,status,matched_count,entered_count,exited_count,eligible_count,suppressed_count,explanation_json,started_by_id,completed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(6))');
            $run->execute([$runId, $context->tenantId, $context->serviceId, $id, $segment['version_number'], 'completed', count($newIds), count($entered), count($exited), $result['eligibleCount'], $result['suppressedCount'], $this->json($result['explanation']), $this->user->getId()]);
            $link = $pdo->prepare('INSERT INTO contact_target_list (contact_id,target_list_id,opted_out,deleted,tenant_id,service_id) VALUES (?,?,0,0,?,?) ON DUPLICATE KEY UPDATE deleted=0');
            foreach ($newIds as $contactId) $link->execute([$contactId, $id, $context->tenantId, $context->serviceId]);
            if ($exited) {
                $placeholders = implode(',', array_fill(0, count($exited), '?'));
                $unlink = $pdo->prepare("UPDATE contact_target_list SET deleted=1 WHERE tenant_id=? AND service_id=? AND target_list_id=? AND contact_id IN ({$placeholders})");
                $unlink->execute([$context->tenantId, $context->serviceId, $id, ...$exited]);
            }
            $event = $pdo->prepare('INSERT INTO nexa_segment_membership_event (id,tenant_id,service_id,target_list_id,run_id,contact_id,event_type,explanation_json) VALUES (?,?,?,?,?,?,?,?)');
            foreach (['entered' => $entered, 'exited' => $exited] as $type => $contactIds) {
                foreach ($contactIds as $contactId) $event->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $runId, $contactId, $type, $this->json($result['explanation'])]);
            }
            $update = $pdo->prepare('UPDATE nexa_segment_definition SET member_count=?,eligible_count=?,suppressed_count=?,last_calculated_at=NOW(6),last_calculated_by_id=? WHERE tenant_id=? AND service_id=? AND target_list_id=?');
            $update->execute([count($newIds), $result['eligibleCount'], $result['suppressedCount'], $this->user->getId(), $context->tenantId, $context->serviceId, $id]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['id' => $id, 'memberCount' => count($newIds), 'eligibleCount' => $result['eligibleCount'], 'suppressedCount' => $result['suppressedCount'], 'enteredCount' => count($entered), 'exitedCount' => count($exited), 'runId' => $runId];
    }

    public function recalculateAll(): void
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare("SELECT target_list_id FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND status='active' AND segment_type='dynamic'");
        $statement->execute([$context->tenantId, $context->serviceId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) $this->recalculate((string) $id);
    }

    public function archive(string $id): void
    {
        $this->requireAccess(Table::ACTION_DELETE);
        $context = $this->tenantContextStore->require();
        $this->requireSegment($context, $id);
        $this->recordServices->get('TargetList')->delete($id, DeleteParams::create());
        $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_segment_definition SET status='archived',modified_by_id=?,modified_at=NOW(6) WHERE tenant_id=? AND service_id=? AND target_list_id=?");
        $statement->execute([$this->user->getId(), $context->tenantId, $context->serviceId, $id]);
    }

    /** @return array<string, mixed> */
    public function duplicate(string $id): array
    {
        $this->requireAccess(Table::ACTION_CREATE);
        $context = $this->tenantContextStore->require();
        $segment = $this->requireSegment($context, $id);
        $target = $this->recordServices->get('TargetList')->read($id, ReadParams::create());
        $result = $this->save((object) [
            'name' => 'Copy of ' . (string) $target->get('name'),
            'description' => $target->get('description'),
            'type' => $segment['segment_type'],
            'folder' => $segment['folder_name'],
            'accessLevel' => $segment['access_level'],
            'matchMode' => $segment['match_mode'],
            'rules' => json_decode((string) $segment['rules_json']),
            'exclusionRules' => json_decode((string) $segment['exclusion_rules_json']),
            'snapshotFromRules' => (bool) $segment['snapshot_from_rules'],
        ]);
        if ($segment['segment_type'] === 'static' && !(bool) $segment['snapshot_from_rules']) {
            $copy = $this->entityManager->getPDO()->prepare('INSERT INTO contact_target_list (contact_id,target_list_id,opted_out,deleted,tenant_id,service_id) SELECT contact_id,?,opted_out,deleted,tenant_id,service_id FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0');
            $copy->execute([$result['id'], $context->tenantId, $context->serviceId, $id]);
            $this->refreshCounts($context, (string) $result['id']);
        }
        return $result;
    }

    /** @return array<int, array<string, mixed>> */
    public function export(string $id): array
    {
        $this->requireAccess(Table::ACTION_READ);
        $context = $this->tenantContextStore->require();
        $this->requireSegment($context, $id);
        $statement = $this->entityManager->getPDO()->prepare("SELECT c.id,TRIM(CONCAT_WS(' ',c.first_name,c.last_name)) AS name,ea.name AS email,pn.name AS phone,a.name AS account,c.lifecycle_stage AS lifecycleStage,c.marketing_status AS marketingStatus,c.address_country AS country FROM contact_target_list ctl INNER JOIN contact c ON c.id=ctl.contact_id AND c.tenant_id=ctl.tenant_id AND c.service_id=ctl.service_id LEFT JOIN account a ON a.id=c.account_id AND a.tenant_id=c.tenant_id AND a.service_id=c.service_id LEFT JOIN entity_email_address eea ON eea.entity_id=c.id AND eea.entity_type='Contact' AND eea.`primary`=1 AND eea.deleted=0 AND eea.tenant_id=c.tenant_id AND eea.service_id=c.service_id LEFT JOIN email_address ea ON ea.id=eea.email_address_id AND ea.tenant_id=c.tenant_id AND ea.service_id=c.service_id LEFT JOIN entity_phone_number epn ON epn.entity_id=c.id AND epn.entity_type='Contact' AND epn.`primary`=1 AND epn.deleted=0 AND epn.tenant_id=c.tenant_id AND epn.service_id=c.service_id LEFT JOIN phone_number pn ON pn.id=epn.phone_number_id AND pn.tenant_id=c.tenant_id AND pn.service_id=c.service_id AND pn.deleted=0 WHERE ctl.tenant_id=? AND ctl.service_id=? AND ctl.target_list_id=? AND ctl.deleted=0 AND c.deleted=0 ORDER BY c.last_name,c.first_name,c.id");
        $statement->execute([$context->tenantId, $context->serviceId, $id]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array<string, mixed>> */
    private function segments(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT d.target_list_id AS id,t.name,t.description,d.segment_type AS type,d.status,d.folder_name AS folder,d.access_level AS accessLevel,d.match_mode AS matchMode,d.rules_json AS rulesJson,d.exclusion_rules_json AS exclusionRulesJson,d.snapshot_from_rules AS snapshotFromRules,d.version_number AS version,d.member_count AS memberCount,d.eligible_count AS eligibleCount,d.suppressed_count AS suppressedCount,d.last_calculated_at AS lastCalculatedAt,d.modified_at AS modifiedAt,COALESCE(NULLIF(TRIM(CONCAT_WS(' ',u.first_name,u.last_name)),''),u.user_name) AS modifiedByName FROM nexa_segment_definition d INNER JOIN target_list t ON t.id=d.target_list_id AND t.tenant_id=d.tenant_id AND t.service_id=d.service_id LEFT JOIN user u ON u.id=d.modified_by_id AND u.tenant_id=d.tenant_id AND u.service_id=d.service_id WHERE d.tenant_id=? AND d.service_id=? AND d.status='active' AND t.deleted=0 ORDER BY d.modified_at DESC");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static function (array $row): array {
            $row['rules'] = json_decode((string) $row['rulesJson'], true) ?: [];
            $row['exclusionRules'] = json_decode((string) $row['exclusionRulesJson'], true) ?: [];
            $row['snapshotFromRules'] = (bool) $row['snapshotFromRules'];
            unset($row['rulesJson'], $row['exclusionRulesJson']);
            foreach (['version', 'memberCount', 'eligibleCount', 'suppressedCount'] as $key) $row[$key] = (int) $row[$key];
            return $row;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return string[] */
    private function folders(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT DISTINCT folder_name FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND status='active' AND folder_name IS NOT NULL AND folder_name<>'' ORDER BY folder_name");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function ensureDefinitions(TenantContext $context): void
    {
        $statement = $this->entityManager->getPDO()->prepare("INSERT IGNORE INTO nexa_segment_definition (target_list_id,tenant_id,service_id,segment_type,match_mode,rules_json,exclusion_rules_json,created_by_id,modified_by_id) SELECT id,tenant_id,service_id,'static','all',JSON_ARRAY(),JSON_ARRAY(),created_by_id,modified_by_id FROM target_list WHERE tenant_id=? AND service_id=? AND deleted=0");
        $statement->execute([$context->tenantId, $context->serviceId]);
    }

    private function refreshStaticCounts(TenantContext $context): void
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT target_list_id FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND status='active' AND segment_type='static'");
        $statement->execute([$context->tenantId, $context->serviceId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) $this->refreshCounts($context, (string) $id);
    }

    private function refreshCounts(TenantContext $context, string $id): void
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) AS members,SUM(' . $this->eligibleExpression() . ') AS eligible FROM contact_target_list ctl INNER JOIN contact c ON c.id=ctl.contact_id AND c.tenant_id=ctl.tenant_id AND c.service_id=ctl.service_id WHERE ctl.tenant_id=? AND ctl.service_id=? AND ctl.target_list_id=? AND ctl.deleted=0 AND c.deleted=0');
        $statement->execute([$context->tenantId, $context->serviceId, $id]);
        $counts = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        $members = (int) ($counts['members'] ?? 0);
        $eligible = (int) ($counts['eligible'] ?? 0);
        $update = $this->entityManager->getPDO()->prepare('UPDATE nexa_segment_definition SET member_count=?,eligible_count=?,suppressed_count=? WHERE tenant_id=? AND service_id=? AND target_list_id=?');
        $update->execute([$members, $eligible, max(0, $members - $eligible), $context->tenantId, $context->serviceId, $id]);
    }

    /** @return array<string, mixed> */
    private function evaluate(TenantContext $context, string $matchMode, array $rules, array $exclusionRules, bool $limited): array
    {
        [$condition, $params, $explanation] = $this->compileRules($rules, $matchMode);
        if ($exclusionRules) {
            [$excludedCondition, $excludedParams, $excludedExplanation] = $this->compileRules($exclusionRules, 'any');
            $condition .= ' AND NOT (' . $excludedCondition . ')';
            $params = [...$params, ...$excludedParams];
            $explanation = ['includedBy' => $explanation, 'excludedBy' => $excludedExplanation];
        } else {
            $explanation = ['includedBy' => $explanation, 'excludedBy' => []];
        }
        $from = ' FROM contact c LEFT JOIN account a ON a.id=c.account_id AND a.tenant_id=c.tenant_id AND a.service_id=c.service_id AND a.deleted=0 WHERE c.tenant_id=? AND c.service_id=? AND c.deleted=0 AND (' . $condition . ')';
        $arguments = [$context->tenantId, $context->serviceId, ...$params];
        $count = $this->entityManager->getPDO()->prepare('SELECT COUNT(*) AS members,SUM(' . $this->eligibleExpression() . ') AS eligible' . $from);
        $count->execute($arguments);
        $counts = $count->fetch(PDO::FETCH_ASSOC) ?: [];
        $query = $this->entityManager->getPDO()->prepare("SELECT c.id,TRIM(CONCAT_WS(' ',c.first_name,c.last_name)) AS name,a.name AS accountName,c.lifecycle_stage AS lifecycleStage,c.marketing_status AS marketingStatus,c.address_country AS country" . $from . ' ORDER BY c.created_at DESC,c.id' . ($limited ? ' LIMIT 50' : ''));
        $query->execute($arguments);
        $members = (int) ($counts['members'] ?? 0);
        $eligible = (int) ($counts['eligible'] ?? 0);
        return ['memberCount' => $members, 'eligibleCount' => $eligible, 'suppressedCount' => max(0, $members - $eligible), 'records' => $query->fetchAll(PDO::FETCH_ASSOC), 'explanation' => $explanation];
    }

    /** @return array{0:string,1:array<int,mixed>,2:array<int,array<string,string>>} */
    private function compileRules(array $rules, string $matchMode): array
    {
        $parts = []; $params = []; $explanation = [];
        foreach ($rules as $rule) {
            $field = self::FIELDS[$rule['field']]; $expression = $field['expression']; $operator = $rule['operator']; $value = $rule['value'];
            if ($operator === 'isEmpty') $parts[] = "({$expression} IS NULL OR TRIM(CAST({$expression} AS CHAR))='')";
            elseif ($operator === 'isNotEmpty') $parts[] = "({$expression} IS NOT NULL AND TRIM(CAST({$expression} AS CHAR))<>'')";
            elseif (in_array($operator, ['withinLastDays', 'beforeDays'], true)) {
                $days = max(0, min(3650, (int) $value));
                $parts[] = $operator === 'withinLastDays' ? "{$expression} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)" : "{$expression} < DATE_SUB(NOW(), INTERVAL {$days} DAY)";
            } elseif (in_array($operator, ['greaterThan', 'lessThan'], true)) {
                $parts[] = "CAST({$expression} AS DECIMAL(20,4)) " . ($operator === 'greaterThan' ? '>' : '<') . ' ?'; $params[] = (float) $value;
            } elseif ($operator === 'contains') {
                $parts[] = "LOWER(COALESCE(CAST({$expression} AS CHAR),'')) LIKE ?"; $params[] = '%' . mb_strtolower($value) . '%';
            } else {
                $parts[] = "LOWER(COALESCE(CAST({$expression} AS CHAR),'')) " . ($operator === 'notEquals' ? '<>' : '=') . ' ?'; $params[] = mb_strtolower($value);
            }
            $explanation[] = ['field' => $rule['field'], 'label' => $field['label'], 'operator' => $operator, 'value' => $value];
        }
        return ['(' . implode($matchMode === 'any' ? ') OR (' : ') AND (', $parts) . ')', $params, $explanation];
    }

    /** @return array<int, array{field:string,operator:string,value:string}> */
    private function rules(array $input, bool $required): array
    {
        $rules = [];
        foreach ($input as $item) {
            $item = (array) $item; $field = trim((string) ($item['field'] ?? '')); $operator = trim((string) ($item['operator'] ?? 'equals')); $value = mb_substr(trim((string) ($item['value'] ?? '')), 0, 500);
            if (!isset(self::FIELDS[$field]) || !in_array($operator, self::OPERATORS, true)) throw new BadRequest('Choose a supported property and condition.');
            if (!in_array($operator, self::TYPE_OPERATORS[self::FIELDS[$field]['type']], true)) throw new BadRequest('Choose a condition that is compatible with the selected property.');
            if (!in_array($operator, ['isEmpty', 'isNotEmpty'], true) && $value === '') throw new BadRequest('Enter a value for every segment rule.');
            if (in_array($operator, ['withinLastDays', 'beforeDays'], true) && (!ctype_digit($value) || (int) $value > 3650)) throw new BadRequest('Enter a valid number of days between 0 and 3650.');
            $rules[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }
        if ($required && !$rules) throw new BadRequest('Add at least one rule to a dynamic segment.');
        if (count($rules) > 25) throw new BadRequest('A segment can contain up to 25 rules.');
        return $rules;
    }

    /** @return array<string, mixed> */
    private function definitionInput(stdClass $input): array
    {
        $name = mb_substr(trim((string) ($input->name ?? '')), 0, 255);
        if ($name === '') throw new BadRequest('Enter a segment name.');
        $type = trim((string) ($input->type ?? 'static'));
        if (!in_array($type, ['static', 'dynamic'], true)) throw new BadRequest('Choose a static or dynamic segment.');
        $rules = $this->rules((array) ($input->rules ?? []), $type === 'dynamic' || (bool) ($input->snapshotFromRules ?? false));
        $accessLevel = trim((string) ($input->accessLevel ?? 'everyone'));
        if (!in_array($accessLevel, ['everyone', 'owner', 'admins'], true)) throw new BadRequest('Choose a supported audience access level.');
        return [
            'name' => $name,
            'description' => $this->text($input->description ?? null, 1000),
            'type' => $type,
            'folder' => $this->text($input->folder ?? null, 120),
            'accessLevel' => $accessLevel,
            'matchMode' => $this->matchMode($input->matchMode ?? null),
            'rules' => $rules,
            'exclusionRules' => $this->rules((array) ($input->exclusionRules ?? []), false),
            'snapshotFromRules' => $type === 'static' && (bool) ($input->snapshotFromRules ?? false),
        ];
    }

    private function matchMode(mixed $value): string { return trim((string) $value) === 'any' ? 'any' : 'all'; }

    /** @return array<string, mixed> */
    private function requireSegment(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare("SELECT * FROM nexa_segment_definition WHERE tenant_id=? AND service_id=? AND target_list_id=? AND status='active' LIMIT 1");
        $statement->execute([$context->tenantId, $context->serviceId, $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('The segment is unavailable.');
        return $row;
    }

    /** @return string[] */
    private function currentMemberIds(TenantContext $context, string $id): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT contact_id FROM contact_target_list WHERE tenant_id=? AND service_id=? AND target_list_id=? AND deleted=0');
        $statement->execute([$context->tenantId, $context->serviceId, $id]);
        return $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function insertVersion(TenantContext $context, string $id, int $version, array $definition): void
    {
        $json = $this->json($definition['rules']);
        $exclusionJson = $this->json($definition['exclusionRules']);
        $statement = $this->entityManager->getPDO()->prepare('INSERT INTO nexa_segment_version (id,tenant_id,service_id,target_list_id,version_number,segment_type,folder_name,access_level,match_mode,rules_json,exclusion_rules_json,snapshot_from_rules,configuration_hash,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $statement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $version, $definition['type'], $definition['folder'], $definition['accessLevel'], $definition['matchMode'], $json, $exclusionJson, $definition['snapshotFromRules'] ? 1 : 0, hash('sha256', $definition['type'] . '|' . $definition['matchMode'] . '|' . $json . '|' . $exclusionJson), $this->user->getId()]);
    }

    private function eligibleExpression(): string
    {
        return "c.marketing_status='Marketing' AND c.do_not_contact=0 AND (c.do_not_contact_channels IS NULL OR FIND_IN_SET('email',c.do_not_contact_channels)=0) AND EXISTS (SELECT 1 FROM nexa_consent_state cs INNER JOIN nexa_consent_purpose cp ON cp.id=cs.purpose_id AND cp.tenant_id=cs.tenant_id AND cp.service_id=cs.service_id WHERE cs.tenant_id=c.tenant_id AND cs.service_id=c.service_id AND cs.contact_id=c.id AND cp.purpose_key='marketing_communications' AND cs.channel='email' AND cs.status='granted' AND (cs.expires_at IS NULL OR cs.expires_at>NOW(6)))";
    }

    private function requireAccess(string $action): void
    {
        if (!$this->user->isAdmin() || !$this->acl->checkScope('TargetList', $action)) {
            throw new Forbidden('Tenant administrator access is required to manage lists and segments.');
        }
    }

    private function text(mixed $value, int $max): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : mb_substr($value, 0, $max); }
    private function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); }
    private function uuid(): string { $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)); }
}
