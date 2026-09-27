<?php

namespace Espo\Custom\Tools\Form;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Record\UpdateParams;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Language;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use PDO;
use stdClass;

/** Tenant form governance layered on EspoCRM's native Lead Capture engine. */
final class FormWorkspaceService
{
    private const ALLOWED_TYPES = [
        'varchar', 'email', 'phone', 'text', 'personName', 'enum', 'multiEnum',
        'array', 'checklist', 'int', 'float', 'currency', 'date', 'datetime',
        'bool', 'url', 'urlMultiple', 'address',
    ];
    private const EXCLUDED_FIELDS = [
        'id', 'name', 'deleted', 'createdAt', 'modifiedAt', 'createdBy', 'modifiedBy',
        'assignedUser', 'assignedUsers', 'teams', 'followers', 'campaign', 'targetLists',
        'createdAccount', 'createdContact', 'createdOpportunity', 'acceptanceStatus',
        'emailAddressIsInvalid', 'emailAddressIsOptedOut', 'phoneNumberIsInvalid',
        'phoneNumberIsOptedOut', 'originalEmail', 'nexaCustomPropertyFilter',
    ];

    public function __construct(
        private \Espo\Core\ORM\EntityManager $entityManager,
        private ServiceContainer $recordServiceContainer,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Metadata $metadata,
        private Language $language,
        private Config $config,
    ) {}

    /** @return array<string, mixed> */
    public function getWorkspace(): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $this->ensureProfiles($context);
        $submissions = $this->submissionPage($context, 0, 100, '', 'createdAt', 'desc');

        return [
            'forms' => $this->forms($context),
            'fieldCatalog' => $this->fieldCatalog(),
            'purposes' => $this->consentPurposes($context),
            'targetLists' => $this->namedRecords($context, 'target_list'),
            'teams' => $this->namedRecords($context, 'team'),
            'themes' => $this->themeCatalog(),
            'recentSubmissions' => $submissions['list'],
            'submissionTotal' => $submissions['total'],
            'permissions' => [
                'create' => $this->user->isAdmin(),
                'edit' => $this->user->isAdmin(),
                'archive' => $this->user->isAdmin(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function save(stdClass $input, ?string $id = null): array
    {
        $context = $this->tenantContextStore->require();
        $configuration = $this->configuration($input);

        if ($id === null) {
            $this->requireAdmin();
            $entity = $this->recordServiceContainer
                ->get('LeadCapture')
                ->create((object) $this->nativePayload($configuration, false), CreateParams::create());
            $id = $entity->getId();
            $statement = $this->entityManager->getPDO()->prepare(
                'INSERT INTO nexa_form_profile '
                . '(lead_capture_id,tenant_id,service_id,status,description,draft_configuration_json,consent_purpose_id,consent_channel,consent_label,progressive_profiling,conditional_rules_json,field_mapping_json,created_by_id,modified_by_id) '
                . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $statement->execute([
                $id, $context->tenantId, $context->serviceId, 'draft', $configuration['description'],
                $this->json($configuration), $configuration['consentPurposeId'], $configuration['consentChannel'],
                $configuration['consentLabel'], (int) $configuration['progressiveProfiling'],
                $this->json($configuration['conditionalRules']), $this->json($configuration['fieldMapping']),
                $this->user->getId(), $this->user->getId(),
            ]);
        } else {
            $this->requireAdmin();
            $this->requireProfile($context, $id);
            $statement = $this->entityManager->getPDO()->prepare(
                "UPDATE nexa_form_profile SET status=IF(status='archived','draft',status),has_unpublished_changes=1,description=?,draft_configuration_json=?,consent_purpose_id=?,consent_channel=?,consent_label=?,progressive_profiling=?,conditional_rules_json=?,field_mapping_json=?,archived_at=NULL,modified_by_id=? WHERE lead_capture_id=? AND tenant_id=? AND service_id=?"
            );
            $statement->execute([
                $configuration['description'], $this->json($configuration), $configuration['consentPurposeId'],
                $configuration['consentChannel'], $configuration['consentLabel'], (int) $configuration['progressiveProfiling'],
                $this->json($configuration['conditionalRules']), $this->json($configuration['fieldMapping']),
                $this->user->getId(), $id, $context->tenantId, $context->serviceId,
            ]);
        }

        return $this->getWorkspace() + ['savedId' => $id];
    }

    /** @return array<string, mixed> */
    public function publish(string $id): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $profile = $this->requireProfile($context, $id);
        $configuration = json_decode((string) $profile['draft_configuration_json'], true, flags: JSON_THROW_ON_ERROR);
        $this->recordServiceContainer
            ->get('LeadCapture')
            ->update($id, (object) $this->nativePayload($configuration, true), UpdateParams::create());

        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT version_number FROM nexa_form_profile WHERE lead_capture_id=? AND tenant_id=? AND service_id=? FOR UPDATE');
            $lock->execute([$id, $context->tenantId, $context->serviceId]);
            $version = ((int) $lock->fetchColumn()) + 1;
            $snapshot = $this->json($configuration);
            $versionStatement = $pdo->prepare('INSERT INTO nexa_form_version (id,tenant_id,service_id,lead_capture_id,version_number,configuration_json,configuration_hash,published_by_id) VALUES (?,?,?,?,?,?,?,?)');
            $versionStatement->execute([$this->uuid(), $context->tenantId, $context->serviceId, $id, $version, $snapshot, hash('sha256', $snapshot), $this->user->getId()]);
            $update = $pdo->prepare("UPDATE nexa_form_profile SET status='published',version_number=?,has_unpublished_changes=0,published_at=NOW(6),archived_at=NULL,modified_by_id=? WHERE lead_capture_id=? AND tenant_id=? AND service_id=?");
            $update->execute([$version, $this->user->getId(), $id, $context->tenantId, $context->serviceId]);
            if ($ownsTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        return $this->getWorkspace();
    }

    /** @return array<string, mixed> */
    public function archive(string $id): array
    {
        $this->requireAdmin();
        $context = $this->tenantContextStore->require();
        $this->requireProfile($context, $id);
        $this->recordServiceContainer->get('LeadCapture')->update(
            $id,
            (object) ['isActive' => false, 'formEnabled' => false],
            UpdateParams::create(),
        );
        $statement = $this->entityManager->getPDO()->prepare("UPDATE nexa_form_profile SET status='archived',archived_at=NOW(6),modified_by_id=? WHERE lead_capture_id=? AND tenant_id=? AND service_id=?");
        $statement->execute([$this->user->getId(), $id, $context->tenantId, $context->serviceId]);
        return $this->getWorkspace();
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int, offset: int, limit: int} */
    public function getSubmissions(int $offset, int $limit, string $search, string $orderBy, string $direction): array
    {
        $this->requireAdmin();
        return $this->submissionPage(
            $this->tenantContextStore->require(),
            max(0, $offset),
            max(1, min(100, $limit)),
            mb_substr(trim($search), 0, 200),
            $orderBy,
            $direction,
        );
    }

    /** @return array<string, mixed> */
    public function deleteSubmission(string $id): array
    {
        $this->requireAdmin();
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) throw new BadRequest('Select a valid form submission.');
        $context = $this->tenantContextStore->require();
        $deletedLead = false;

        $this->entityManager->getTransactionManager()->run(function () use ($id, $context, &$deletedLead): void {
            $statement = $this->entityManager->getPDO()->prepare(
                'SELECT r.id,r.is_created,r.target_id,r.target_type FROM lead_capture_log_record r '
                . 'INNER JOIN nexa_form_profile p ON p.lead_capture_id=r.lead_capture_id AND p.tenant_id=r.tenant_id AND p.service_id=r.service_id '
                . 'WHERE r.id=? AND r.tenant_id=? AND r.service_id=? AND r.deleted=0 LIMIT 1 FOR UPDATE'
            );
            $statement->execute([$id, $context->tenantId, $context->serviceId]);
            $submission = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$submission) throw new NotFound('The form submission is unavailable.');

            if ((bool) $submission['is_created'] && $submission['target_type'] === 'Lead' && $submission['target_id']) {
                $lead = $this->entityManager->getRDBRepository('Lead')->getById((string) $submission['target_id']);
                if ($lead) {
                    $this->recordServiceContainer->get('Lead')->delete($lead->getId(), DeleteParams::create());
                    $deletedLead = true;
                }
            }

            $deleteLog = $this->entityManager->getPDO()->prepare(
                'UPDATE lead_capture_log_record SET deleted=1 WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0'
            );
            $deleteLog->execute([$id, $context->tenantId, $context->serviceId]);
        });

        return ['id' => $id, 'deleted' => true, 'deletedLead' => $deletedLead];
    }

    private function ensureProfiles(TenantContext $context): void
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT l.* FROM lead_capture l LEFT JOIN nexa_form_profile p ON p.lead_capture_id=l.id AND p.tenant_id=l.tenant_id AND p.service_id=l.service_id '
            . 'WHERE l.tenant_id=? AND l.service_id=? AND l.deleted=0 AND p.lead_capture_id IS NULL'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        $insert = $this->entityManager->getPDO()->prepare(
            'INSERT INTO nexa_form_profile (lead_capture_id,tenant_id,service_id,status,version_number,has_unpublished_changes,description,draft_configuration_json,progressive_profiling,conditional_rules_json,field_mapping_json,created_by_id,modified_by_id,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $configuration = $this->configurationFromNative($row);
            $published = (bool) $row['form_enabled'] && (bool) $row['is_active'];
            $insert->execute([
                $row['id'], $context->tenantId, $context->serviceId, $published ? 'published' : 'draft',
                $published ? 1 : 0, 0, null, $this->json($configuration), 0, '[]', $this->json($configuration['fieldMapping']),
                $row['created_by_id'], $row['modified_by_id'], $published ? ($row['modified_at'] ?: $row['created_at']) : null,
            ]);
            if ($published) {
                $snapshot = $this->json($configuration);
                $version = $this->entityManager->getPDO()->prepare('INSERT IGNORE INTO nexa_form_version (id,tenant_id,service_id,lead_capture_id,version_number,configuration_json,configuration_hash,published_by_id,published_at) VALUES (?,?,?,?,?,?,?,?,?)');
                $version->execute([$this->uuid(), $context->tenantId, $context->serviceId, $row['id'], 1, $snapshot, hash('sha256', $snapshot), $row['modified_by_id'], $row['modified_at'] ?: $row['created_at'] ?: gmdate('Y-m-d H:i:s')]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function forms(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT p.*,l.form_id,l.form_theme,l.created_at,l.modified_at,COALESCE(e.views,0) AS view_count,COALESCE(s.total,0) AS submission_count,COALESCE(s.created_count,0) AS created_count '
            . 'FROM nexa_form_profile p INNER JOIN lead_capture l ON l.id=p.lead_capture_id AND l.tenant_id=p.tenant_id AND l.service_id=p.service_id '
            . 'LEFT JOIN (SELECT lead_capture_id,tenant_id,service_id,COUNT(*) total,SUM(is_created=1) created_count FROM lead_capture_log_record WHERE deleted=0 GROUP BY lead_capture_id,tenant_id,service_id) s '
            . 'ON s.lead_capture_id=p.lead_capture_id AND s.tenant_id=p.tenant_id AND s.service_id=p.service_id '
            . "LEFT JOIN (SELECT lead_capture_id,tenant_id,service_id,COUNT(*) views FROM nexa_form_event WHERE event_type='view' GROUP BY lead_capture_id,tenant_id,service_id) e "
            . 'ON e.lead_capture_id=p.lead_capture_id AND e.tenant_id=p.tenant_id AND e.service_id=p.service_id '
            . 'WHERE p.tenant_id=? AND p.service_id=? AND l.deleted=0 ORDER BY p.modified_at DESC'
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        $siteUrl = rtrim((string) $this->config->get('siteUrl'), '/');
        return array_map(function (array $row) use ($siteUrl): array {
            $configuration = json_decode((string) $row['draft_configuration_json'], true) ?: [];
            $configuration['formTheme'] ??= $row['form_theme'] ?: 'Espo';
            $views = (int) $row['view_count'];
            $submissions = (int) $row['submission_count'];
            return [
                'id' => $row['lead_capture_id'], 'status' => $row['status'], 'version' => (int) $row['version_number'],
                'hasUnpublishedChanges' => (bool) $row['has_unpublished_changes'],
                'configuration' => $configuration, 'viewCount' => $views, 'submissionCount' => $submissions,
                'conversionRate' => $views > 0 ? round(($submissions / $views) * 100, 1) : 0.0,
                'createdRecordCount' => (int) $row['created_count'], 'createdAt' => $row['created_at'],
                'modifiedAt' => $row['modified_at'], 'publishedAt' => $row['published_at'],
                'formUrl' => $row['form_id'] ? $siteUrl . '/?entryPoint=LeadCaptureForm&id=' . rawurlencode((string) $row['form_id']) : null,
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array{list: array<int, array<string, mixed>>, total: int, offset: int, limit: int} */
    private function submissionPage(TenantContext $context, int $offset, int $limit, string $search, string $orderBy, string $direction): array
    {
        $orders = [
            'submittedBy' => "LOWER(CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.firstName')),JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.lastName')),JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.emailAddress'))))",
            'formName' => "LOWER(JSON_UNQUOTE(JSON_EXTRACT(p.draft_configuration_json,'$.name')))",
            'result' => 'r.target_type',
            'createdAt' => 'r.created_at',
        ];
        $order = $orders[$orderBy] ?? $orders['createdAt'];
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
        $where = 'r.tenant_id=? AND r.service_id=? AND r.deleted=0';
        $params = [$context->tenantId, $context->serviceId];
        if ($search !== '') {
            $where .= " AND LOWER(CONCAT_WS(' ',JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.firstName')),JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.lastName')),JSON_UNQUOTE(JSON_EXTRACT(r.data,'$.emailAddress')),JSON_UNQUOTE(JSON_EXTRACT(p.draft_configuration_json,'$.name')),r.target_type)) LIKE ?";
            $params[] = '%' . mb_strtolower($search) . '%';
        }
        $count = $this->entityManager->getPDO()->prepare(
            'SELECT COUNT(*) FROM lead_capture_log_record r INNER JOIN nexa_form_profile p ON p.lead_capture_id=r.lead_capture_id AND p.tenant_id=r.tenant_id AND p.service_id=r.service_id WHERE ' . $where
        );
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT r.id,r.lead_capture_id,r.number,r.data,r.is_created,r.description,r.target_id,r.target_type,r.created_at,p.draft_configuration_json '
            . 'FROM lead_capture_log_record r INNER JOIN nexa_form_profile p ON p.lead_capture_id=r.lead_capture_id AND p.tenant_id=r.tenant_id AND p.service_id=r.service_id '
            . 'WHERE ' . $where . " ORDER BY {$order} {$direction},r.id {$direction} LIMIT {$limit} OFFSET {$offset}"
        );
        $statement->execute($params);
        $list = array_map(static function (array $row): array {
            $data = json_decode((string) ($row['data'] ?? '{}'), true) ?: [];
            $config = json_decode((string) $row['draft_configuration_json'], true) ?: [];
            return [
                'id' => $row['id'], 'formId' => $row['lead_capture_id'], 'formName' => $config['name'] ?? 'Form',
                'number' => (int) $row['number'], 'isCreated' => (bool) $row['is_created'],
                'description' => $row['description'], 'targetId' => $row['target_id'], 'targetType' => $row['target_type'],
                'createdAt' => $row['created_at'], 'name' => trim(($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? '')),
                'email' => $data['emailAddress'] ?? null,
            ];
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['list' => $list, 'total' => $total, 'offset' => $offset, 'limit' => $limit];
    }

    /** @return array<int, array<string, mixed>> */
    private function fieldCatalog(): array
    {
        $fields = $this->metadata->get(['entityDefs', 'Lead', 'fields']) ?? [];
        $catalog = [];
        foreach ($fields as $name => $defs) {
            $type = (string) ($defs['type'] ?? 'varchar');
            if (in_array($name, self::EXCLUDED_FIELDS, true) || !in_array($type, self::ALLOWED_TYPES, true) || ($defs['readOnly'] ?? false) || ($defs['notStorable'] ?? false)) continue;
            $label = $this->language->translate($name, 'fields', 'Lead');
            $catalog[] = ['name' => $name, 'label' => $label !== $name ? $label : ucfirst(preg_replace('/(?<!^)[A-Z]/', ' $0', $name)), 'type' => $type, 'required' => (bool) ($defs['required'] ?? false)];
        }
        usort($catalog, static fn (array $a, array $b): int => strcasecmp((string) $a['label'], (string) $b['label']));
        return $catalog;
    }

    /** @return array<int, array<string, mixed>> */
    private function consentPurposes(TenantContext $context): array
    {
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,name,channels_json,policy_version FROM nexa_consent_purpose WHERE tenant_id=? AND service_id=? AND is_active=1 ORDER BY position,name');
        $statement->execute([$context->tenantId, $context->serviceId]);
        return array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'channels' => json_decode((string) $row['channels_json'], true) ?: [], 'policyVersion' => $row['policy_version']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<int, array{id: string, name: string}> */
    private function namedRecords(TenantContext $context, string $table): array
    {
        if (!in_array($table, ['target_list', 'team'], true)) return [];
        $statement = $this->entityManager->getPDO()->prepare("SELECT id,name FROM `$table` WHERE tenant_id=? AND service_id=? AND deleted=0 ORDER BY name LIMIT 500");
        $statement->execute([$context->tenantId, $context->serviceId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed> */
    private function configuration(stdClass $input): array
    {
        $name = trim((string) ($input->name ?? ''));
        if ($name === '' || mb_strlen($name) > 100) throw new BadRequest('Enter a form name of 100 characters or fewer.');
        $available = array_column($this->fieldCatalog(), null, 'name');
        $fields = [];
        foreach ((array) ($input->fields ?? []) as $field) {
            $field = (array) $field;
            $key = trim((string) ($field['name'] ?? ''));
            if (!isset($available[$key]) || isset($fields[$key])) continue;
            $fields[$key] = ['name' => $key, 'required' => (bool) ($field['required'] ?? false) || (bool) $available[$key]['required']];
        }
        if ($fields === []) throw new BadRequest('Add at least one field to the form.');
        $redirectUrl = trim((string) ($input->redirectUrl ?? '')) ?: null;
        if ($redirectUrl !== null && filter_var($redirectUrl, FILTER_VALIDATE_URL) === false) throw new BadRequest('Enter a valid success redirect URL.');
        $purposeId = trim((string) ($input->consentPurposeId ?? '')) ?: null;
        $channel = trim((string) ($input->consentChannel ?? '')) ?: null;
        if ($purposeId !== null && !in_array($channel, ['email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'], true)) throw new BadRequest('Select a valid consent channel.');
        $frameAncestors = array_values(array_filter(array_map('trim', (array) ($input->frameAncestors ?? []))));
        foreach ($frameAncestors as $origin) if (filter_var($origin, FILTER_VALIDATE_URL) === false) throw new BadRequest('Enter valid allowed website URLs.');
        $theme = trim((string) ($input->formTheme ?? 'Espo')) ?: 'Espo';
        if (!in_array($theme, array_column($this->themeCatalog(), 'id'), true)) throw new BadRequest('Select a supported form theme.');
        $required = [];
        $fieldList = [];
        foreach ($fields as $field) {
            $fieldList[] = $field['name'];
            if ($field['required']) $required[] = $field['name'];
        }
        return [
            'name' => $name, 'description' => $this->text($input->description ?? null, 1000),
            'title' => $this->text($input->title ?? $name, 80) ?: $name,
            'formTheme' => $theme,
            'intro' => $this->text($input->intro ?? null, 2000),
            'successMessage' => $this->text($input->successMessage ?? 'Thanks. Your information has been received.', 2000),
            'redirectUrl' => $redirectUrl,
            'redirectDelaySeconds' => max(1, min(30, (int) ($input->redirectDelaySeconds ?? 4))),
            'language' => trim((string) ($input->language ?? 'en_US')) ?: 'en_US',
            'frameAncestors' => $frameAncestors, 'captcha' => (bool) ($input->captcha ?? false),
            'duplicateCheck' => (bool) ($input->duplicateCheck ?? true), 'leadSource' => trim((string) ($input->leadSource ?? 'Web Site')) ?: 'Web Site',
            'targetListId' => trim((string) ($input->targetListId ?? '')) ?: null, 'targetTeamId' => trim((string) ($input->targetTeamId ?? '')) ?: null,
            'subscribeToTargetList' => (bool) ($input->subscribeToTargetList ?? false),
            'fieldList' => $fieldList, 'requiredFields' => $required,
            'fieldMapping' => array_combine($fieldList, $fieldList) ?: [],
            'consentPurposeId' => $purposeId, 'consentChannel' => $channel,
            'consentLabel' => $this->text($input->consentLabel ?? null, 500),
            'progressiveProfiling' => (bool) ($input->progressiveProfiling ?? false),
            'conditionalRules' => array_values((array) ($input->conditionalRules ?? [])),
        ];
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function nativePayload(array $configuration, bool $published): array
    {
        $fieldParams = [];
        foreach ($configuration['fieldList'] as $field) $fieldParams[$field] = ['required' => in_array($field, $configuration['requiredFields'], true)];
        return [
            'name' => $configuration['name'], 'isActive' => true, 'formEnabled' => $published,
            'fieldList' => $configuration['fieldList'], 'fieldParams' => (object) $fieldParams,
            'duplicateCheck' => $configuration['duplicateCheck'], 'leadSource' => $configuration['leadSource'],
            'targetListId' => $configuration['targetListId'], 'targetTeamId' => $configuration['targetTeamId'],
            'subscribeToTargetList' => $configuration['subscribeToTargetList'], 'subscribeContactToTargetList' => $configuration['subscribeToTargetList'],
            'formTitle' => $configuration['title'], 'formText' => $configuration['intro'], 'formSuccessText' => $configuration['successMessage'],
            'formTheme' => $configuration['formTheme'],
            'formSuccessRedirectUrl' => $configuration['redirectUrl'], 'formLanguage' => $configuration['language'],
            'formFrameAncestors' => $configuration['frameAncestors'], 'formCaptcha' => $configuration['captcha'],
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function configurationFromNative(array $row): array
    {
        $fieldList = json_decode((string) ($row['field_list'] ?? '[]'), true) ?: ['firstName', 'lastName', 'emailAddress'];
        $params = json_decode((string) ($row['field_params'] ?? '{}'), true) ?: [];
        $required = array_values(array_filter($fieldList, static fn (string $field): bool => (bool) ($params[$field]['required'] ?? false)));
        return [
            'name' => $row['name'] ?: 'Untitled form', 'description' => null, 'title' => $row['form_title'] ?: $row['name'],
            'formTheme' => $row['form_theme'] ?: 'Espo',
            'intro' => $row['form_text'], 'successMessage' => $row['form_success_text'], 'redirectUrl' => $row['form_success_redirect_url'],
            'language' => $row['form_language'] ?: 'en_US', 'frameAncestors' => json_decode((string) ($row['form_frame_ancestors'] ?? '[]'), true) ?: [],
            'captcha' => (bool) $row['form_captcha'], 'duplicateCheck' => (bool) $row['duplicate_check'], 'leadSource' => $row['lead_source'] ?: 'Web Site',
            'targetListId' => $row['target_list_id'], 'targetTeamId' => $row['target_team_id'], 'subscribeToTargetList' => (bool) $row['subscribe_to_target_list'],
            'fieldList' => $fieldList, 'requiredFields' => $required, 'fieldMapping' => array_combine($fieldList, $fieldList) ?: [],
            'consentPurposeId' => null, 'consentChannel' => null, 'consentLabel' => null, 'progressiveProfiling' => false, 'conditionalRules' => [],
        ];
    }

    /** @return array<int, array{id: string, name: string}> */
    private function themeCatalog(): array
    {
        $themes = $this->metadata->get(['themes']) ?? [];
        $catalog = [];

        foreach ($themes as $name => $definitions) {
            if (!is_array($definitions) || (!isset($definitions['stylesheetIframe']) && !isset($definitions['stylesheetIframeFallback']))) continue;
            $label = $this->language->translate((string) $name, 'themes', 'Global');
            $catalog[] = ['id' => (string) $name, 'name' => $label !== $name ? $label : (string) $name];
        }

        usort($catalog, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $catalog;
    }

    /** @return array<string, mixed> */
    private function requireProfile(TenantContext $context, string $id): array
    {
        if (!preg_match('/^[a-zA-Z0-9]{17}$/', $id)) throw new BadRequest('Select a valid form.');
        $statement = $this->entityManager->getPDO()->prepare('SELECT * FROM nexa_form_profile WHERE lead_capture_id=? AND tenant_id=? AND service_id=? LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new NotFound('Form not found.');
        return $row;
    }

    private function requireAdmin(): void
    {
        // Native LeadCapture is an admin-only scope (`acl: false`). Preserve
        // that boundary until M04 introduces a dedicated form permission.
        if (!$this->user->isAdmin()) throw new Forbidden('Only a tenant administrator can manage forms.');
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        if (mb_strlen($value) > $max) throw new BadRequest("Text must contain $max characters or fewer.");
        return $value;
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
