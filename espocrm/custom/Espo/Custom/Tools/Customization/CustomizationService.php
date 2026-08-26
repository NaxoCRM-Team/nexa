<?php

namespace Espo\Custom\Tools\Customization;

use Espo\Core\Acl;
use Espo\Core\Acl\Table as AclTable;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use PDO;

/** Tenant boundary for fields, layouts, objects and relationships. */
final class CustomizationService
{
    private const NATIVE = ['Contact', 'Account'];
    private const TYPES = ['text','long_text','number','currency','date','datetime','boolean','single_select','multi_select','url','email','phone','user','relationship'];

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private User $user,
        private Acl $acl,
        private SelectBuilderFactory $selectBuilderFactory,
        private Metadata $metadata,
    ) {}

    /** @return array<string, mixed> */
    public function definitions(?string $entityType = null): array
    {
        $context = $this->tenantContextStore->require();
        $scope = [$context->tenantId, $context->serviceId];
        $entityType = $entityType ? $this->entityType($entityType) : null;
        $preferences = $this->propertyPreferenceMap($entityType);
        $fields = $this->all(
            'SELECT id,entity_type,field_key,label,description,data_type,options_json,default_value_json,validation_json,is_required,is_unique,is_filterable,is_searchable,position FROM nexa_custom_field_definition WHERE tenant_id=? AND service_id=? AND is_active=1' . ($entityType ? ' AND entity_type=?' : '') . ' ORDER BY entity_type,position,label',
            $entityType ? [...$scope, $entityType] : $scope,
        );
        foreach ($fields as &$field) {
            $field['options'] = array_values($this->jsonArray($field['options_json']));
            $field['defaultValue'] = $this->jsonValue($field['default_value_json']);
            $field['validation'] = $this->jsonArray($field['validation_json']);
            foreach (['is_required','is_unique','is_filterable','is_searchable'] as $key) $field[$key] = (bool) $field[$key];
            $preferenceKey = $field['entity_type'] . ':' . $field['field_key'];
            $field['is_enabled'] = $preferences[$preferenceKey] ?? true;
            $field['is_protected'] = false;
            unset($field['options_json'], $field['default_value_json'], $field['validation_json']);
        }
        unset($field);
        $layouts = $this->all(
            'SELECT id,entity_type,layout_context,layout_json,version FROM nexa_custom_layout_definition WHERE tenant_id=? AND service_id=?' . ($entityType ? ' AND entity_type=?' : '') . ' ORDER BY entity_type,layout_context',
            $entityType ? [...$scope, $entityType] : $scope,
        );
        foreach ($layouts as &$layout) {
            $layout['layout'] = array_values($this->jsonArray($layout['layout_json']));
            unset($layout['layout_json']);
        }
        unset($layout);

        $standardEntityTypes = $entityType
            ? (in_array($entityType, self::NATIVE, true) ? [$entityType] : [])
            : self::NATIVE;

        return [
            'nativeEntityTypes' => self::NATIVE,
            'fieldTypes' => self::TYPES,
            'entities' => $this->all('SELECT id,entity_key,label,plural_label,description,icon_class,status,created_at,updated_at FROM nexa_custom_entity_definition WHERE tenant_id=? AND service_id=? AND status=\'active\' ORDER BY label', $scope),
            'fields' => $fields,
            'standardFields' => $standardEntityTypes === [] ? [] : array_merge(...array_map(fn (string $type): array => $this->standardProperties($type, $preferences), $standardEntityTypes)),
            'layouts' => $layouts,
            'relationships' => $this->all('SELECT id,relationship_key,label,inverse_label,source_entity_type,target_entity_type,cardinality,is_required FROM nexa_custom_relationship_definition WHERE tenant_id=? AND service_id=? AND is_active=1 ORDER BY label', $scope),
        ];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function saveDefinition(string $kind, array $data): array
    {
        $this->admin();
        return match ($kind) {
            'entity' => $this->saveEntity($data),
            'field' => $this->saveField($data),
            'layout' => $this->saveLayout($data),
            'relationship' => $this->saveRelationship($data),
            'propertyPreference' => $this->savePropertyPreference($data),
            default => throw new BadRequest('Unknown customization definition type.'),
        };
    }

    public function archive(string $kind, string $id): void
    {
        $this->admin();
        $context = $this->tenantContextStore->require();
        [$table, $set] = match ($kind) {
            'entity' => ['nexa_custom_entity_definition', "status='archived',archived_at=CURRENT_TIMESTAMP(6)"],
            'field' => ['nexa_custom_field_definition', 'is_active=0,archived_at=CURRENT_TIMESTAMP(6)'],
            'relationship' => ['nexa_custom_relationship_definition', 'is_active=0,archived_at=CURRENT_TIMESTAMP(6)'],
            'layout' => ['nexa_custom_layout_definition', null],
            default => throw new BadRequest('Unknown customization definition type.'),
        };
        $statement = $this->entityManager->getPDO()->prepare($set ? "UPDATE {$table} SET {$set} WHERE id=? AND tenant_id=? AND service_id=?" : "DELETE FROM {$table} WHERE id=? AND tenant_id=? AND service_id=?");
        $statement->execute([$this->id($id), $context->tenantId, $context->serviceId]);
        if (!$statement->rowCount()) throw new NotFound('Customization definition was not found.');
        $this->audit('customization.definition.archived', $kind, $id);
    }

    /** @return array<string, mixed> */
    public function values(string $entityType, string $entityId): array
    {
        [$entityType, $entityId] = $this->record($entityType, $entityId, false);
        $context = $this->tenantContextStore->require();
        $definitionSet = $this->definitions($entityType);
        $definitions = array_values(array_filter($definitionSet['fields'], fn (array $field): bool => $field['is_enabled']));
        $stored = [];
        foreach ($this->all('SELECT * FROM nexa_custom_field_value WHERE tenant_id=? AND service_id=? AND entity_type=? AND entity_id=?', [$context->tenantId,$context->serviceId,$entityType,$entityId]) as $row) $stored[$row['field_definition_id']] = $row;
        $values = [];
        foreach ($definitions as $definition) $values[$definition['field_key']] = $this->readValue($definition, $stored[$definition['id']] ?? null);
        return [
            'entityType'=>$entityType,
            'entityId'=>$entityId,
            'definitions'=>$definitions,
            'layouts'=>$definitionSet['layouts'],
            'values'=>$values,
        ];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public function saveValues(string $entityType, string $entityId, array $values): array
    {
        [$entityType, $entityId] = $this->record($entityType, $entityId, true);
        $context = $this->tenantContextStore->require();
        $definitions = array_values(array_filter($this->definitions($entityType)['fields'], fn (array $field): bool => $field['is_enabled']));
        $byKey = [];
        foreach ($definitions as $definition) $byKey[$definition['field_key']] = $definition;
        foreach ($values as $key => $value) {
            if (!is_string($key) || !isset($byKey[$key])) throw new BadRequest("Unknown custom property: {$key}.");
            // Validate every submitted value before the first database mutation.
            $this->normalizeValue($byKey[$key], $value);
        }
        $current = $this->values($entityType, $entityId)['values'];
        foreach ($definitions as $definition) {
            $key = $definition['field_key'];
            $effective = array_key_exists($key, $values) ? $values[$key] : ($current[$key] ?? null);
            if ($definition['is_required'] && $this->blank($effective)) throw new BadRequest("{$definition['label']} is required.");
        }

        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'nexa_custom_values';
        if ($ownsTransaction) $pdo->beginTransaction(); else $pdo->exec("SAVEPOINT {$savepoint}");

        try {
            foreach ($values as $key => $value) {
                $this->writeValue($context->tenantId, $context->serviceId, $entityType, $entityId, $byKey[$key], $value);
            }
            $snapshot = $this->values($entityType, $entityId);
            $this->audit('customization.values.updated', $entityType, $entityId, ['fields'=>array_keys($values)]);
            if ($ownsTransaction) $pdo->commit(); else $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            return $snapshot;
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function records(string $entityKey, int $offset = 0, int $limit = 50, string $query = ''): array
    {
        $entity = $this->customEntity($entityKey);
        $context = $this->tenantContextStore->require();
        $offset = max(0, $offset); $limit = min(200, max(1, $limit));
        $query = trim($query);
        $where = 'r.tenant_id=? AND r.service_id=? AND r.custom_entity_id=? AND r.deleted_at IS NULL';
        $params = [$context->tenantId, $context->serviceId, $entity['id']];
        if ($query !== '') {
            $pattern = '%' . addcslashes($query, '%_\\') . '%';
            $where .= " AND (r.display_name LIKE ? ESCAPE '\\\\' OR EXISTS (SELECT 1 FROM nexa_custom_field_value v INNER JOIN nexa_custom_field_definition d ON d.id=v.field_definition_id AND d.tenant_id=v.tenant_id AND d.service_id=v.service_id WHERE v.tenant_id=r.tenant_id AND v.service_id=r.service_id AND v.entity_type=? AND v.entity_id=r.id AND d.is_active=1 AND d.is_searchable=1 AND NOT EXISTS (SELECT 1 FROM nexa_property_preference p WHERE p.tenant_id=d.tenant_id AND p.service_id=d.service_id AND p.entity_type=d.entity_type AND p.field_key=d.field_key AND p.is_enabled=0) AND CONCAT_WS(' ',v.value_text,v.value_number,v.value_date,v.value_datetime,v.value_json) LIKE ? ESCAPE '\\\\'))";
            array_push($params, $pattern, $entity['entity_key'], $pattern);
        }
        $count = $this->one("SELECT COUNT(*) AS total FROM nexa_custom_record r WHERE {$where}", $params);
        $statement = $this->entityManager->getPDO()->prepare("SELECT r.id,r.display_name,r.assigned_user_id,r.created_by_id,r.modified_by_id,r.created_at,r.updated_at FROM nexa_custom_record r WHERE {$where} ORDER BY r.updated_at DESC LIMIT ? OFFSET ?");
        foreach ($params as $index => $value) $statement->bindValue($index + 1, $value);
        $statement->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
        $statement->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
        $statement->execute();
        $records = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($records as &$record) {
            $record['values'] = $this->values($entity['entity_key'], (string) $record['id'])['values'];
        }
        unset($record);
        return ['entity'=>$entity,'records'=>$records,'total'=>(int)($count['total']??0),'offset'=>$offset,'limit'=>$limit,'query'=>$query];
    }

    /** @return array<string, mixed> */
    public function recordWorkspace(string $entityKey, string $recordId): array
    {
        $entity = $this->customEntity($entityKey);
        $context = $this->tenantContextStore->require();
        $this->record($entity['entity_key'], $recordId, false);
        $record = $this->one(
            'SELECT id,display_name,assigned_user_id,created_by_id,modified_by_id,created_at,updated_at FROM nexa_custom_record WHERE id=? AND tenant_id=? AND service_id=? AND custom_entity_id=? AND deleted_at IS NULL',
            [$recordId, $context->tenantId, $context->serviceId, $entity['id']]
        ) ?? throw new NotFound('Custom record was not found.');
        $values = $this->values($entity['entity_key'], $recordId);
        $definitions = $this->definitions($entity['entity_key']);

        return [
            'entity' => $entity,
            'record' => $record,
            'values' => $values['values'],
            'definitions' => $values['definitions'],
            'layouts' => $values['layouts'],
            'relationships' => $definitions['relationships'],
        ];
    }

    public function deleteRecord(string $entityKey, string $recordId): void
    {
        $entity = $this->customEntity($entityKey);
        $context = $this->tenantContextStore->require();
        $this->record($entity['entity_key'], $recordId, true);
        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_custom_record SET deleted_at=CURRENT_TIMESTAMP(6),modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=? AND custom_entity_id=? AND deleted_at IS NULL'
        );
        $statement->execute([$this->user->getId(), $recordId, $context->tenantId, $context->serviceId, $entity['id']]);
        if (!$statement->rowCount()) throw new NotFound('Custom record was not found.');
        $this->audit('customization.record.deleted', $entity['entity_key'], $recordId);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function saveRecord(string $entityKey, array $data): array
    {
        $entity = $this->customEntity($entityKey); $context = $this->tenantContextStore->require();
        $id = isset($data['id']) && is_string($data['id']) ? $this->id($data['id']) : $this->uuid();
        $name = $this->label($data['displayName'] ?? '', 'Record name', 191);
        $submittedValues = $data['values'] ?? [];
        if (is_object($submittedValues)) $submittedValues = get_object_vars($submittedValues);
        if (!is_array($submittedValues)) throw new BadRequest('Custom property values must be an object.');
        $pdo = $this->entityManager->getPDO();
        $ownsTransaction = !$pdo->inTransaction();
        $savepoint = 'nexa_custom_record';

        if ($ownsTransaction) $pdo->beginTransaction(); else $pdo->exec("SAVEPOINT {$savepoint}");

        try {
            $exists = $this->one('SELECT id FROM nexa_custom_record WHERE id=? AND tenant_id=? AND service_id=? AND custom_entity_id=? AND deleted_at IS NULL', [$id,$context->tenantId,$context->serviceId,$entity['id']]);
            if ($exists) {
                $sql = 'UPDATE nexa_custom_record SET display_name=?,modified_by_id=? WHERE id=? AND tenant_id=? AND service_id=?';
                $params = [$name,$this->user->getId(),$id,$context->tenantId,$context->serviceId];
            } else {
                $sql = 'INSERT INTO nexa_custom_record (id,tenant_id,service_id,custom_entity_id,display_name,assigned_user_id,created_by_id,modified_by_id) VALUES (?,?,?,?,?,?,?,?)';
                $params = [$id,$context->tenantId,$context->serviceId,$entity['id'],$name,$this->user->getId(),$this->user->getId(),$this->user->getId()];
            }
            $statement=$pdo->prepare($sql); $statement->execute($params);
            $snapshot=$this->saveValues($entity['entity_key'],$id,$submittedValues);
            $this->audit($exists?'customization.record.updated':'customization.record.created',$entity['entity_key'],$id);
            if ($ownsTransaction) $pdo->commit(); else $pdo->exec("RELEASE SAVEPOINT {$savepoint}");
            return ['id'=>$id,'displayName'=>$name,'customProperties'=>$snapshot];
        } catch (\Throwable $error) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            elseif ($pdo->inTransaction()) $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            throw $error;
        }
    }

    /** @param array<string, mixed> $data */
    public function link(array $data): array
    {
        $context=$this->tenantContextStore->require(); $definitionId=$this->id($data['relationshipDefinitionId']??'');
        $definition=$this->one('SELECT * FROM nexa_custom_relationship_definition WHERE id=? AND tenant_id=? AND service_id=? AND is_active=1',[$definitionId,$context->tenantId,$context->serviceId])??throw new NotFound('Relationship definition was not found.');
        $sourceId=$this->id($data['sourceEntityId']??''); $targetId=$this->id($data['targetEntityId']??'');
        $this->record($definition['source_entity_type'],$sourceId,true); $this->record($definition['target_entity_type'],$targetId,false);
        if ($sourceId===$targetId && $definition['source_entity_type']===$definition['target_entity_type']) throw new BadRequest('A record cannot be related to itself.');
        $this->enforceCardinality($definition, $sourceId, $targetId);
        $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_custom_relationship_link (id,tenant_id,service_id,relationship_definition_id,source_entity_type,source_entity_id,target_entity_type,target_entity_id,created_by_id) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE deleted_at=NULL');
        $statement->execute([$this->uuid(),$context->tenantId,$context->serviceId,$definitionId,$definition['source_entity_type'],$sourceId,$definition['target_entity_type'],$targetId,$this->user->getId()]);
        $this->audit('customization.relationship.linked','relationship',$definitionId,['sourceId'=>$sourceId,'targetId'=>$targetId]);
        return ['success'=>true];
    }

    /**
     * Return the records already connected to one endpoint and safe candidates
     * the current user can connect. Both lists are resolved inside the trusted
     * tenant context; the browser never supplies a tenant or service ID.
     *
     * @return array<string, mixed>
     */
    public function relationshipWorkspace(
        string $definitionId,
        string $entityType,
        string $entityId,
        string $query = ''
    ): array {
        $context = $this->tenantContextStore->require();
        $definition = $this->one(
            'SELECT * FROM nexa_custom_relationship_definition WHERE id=? AND tenant_id=? AND service_id=? AND is_active=1',
            [$this->id($definitionId), $context->tenantId, $context->serviceId]
        ) ?? throw new NotFound('Relationship definition was not found.');

        [$entityType, $entityId] = $this->record($entityType, $entityId, false);
        $isSource = $definition['source_entity_type'] === $entityType;
        if (!$isSource && $definition['target_entity_type'] !== $entityType) {
            throw new BadRequest('The record is not an endpoint of this relationship.');
        }

        $relatedType = $isSource
            ? (string) $definition['target_entity_type']
            : (string) $definition['source_entity_type'];
        $currentColumn = $isSource ? 'source_entity_id' : 'target_entity_id';
        $relatedColumn = $isSource ? 'target_entity_id' : 'source_entity_id';
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT id,{$relatedColumn} AS related_id,created_at FROM nexa_custom_relationship_link " .
            "WHERE tenant_id=? AND service_id=? AND relationship_definition_id=? AND {$currentColumn}=? AND deleted_at IS NULL ORDER BY created_at DESC"
        );
        $statement->execute([$context->tenantId, $context->serviceId, $definition['id'], $entityId]);

        $links = [];
        $linkedIds = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $link) {
            $option = $this->recordOption($relatedType, (string) $link['related_id']);
            if (!$option) continue;
            $linkedIds[] = $option['id'];
            $links[] = [
                'id' => $link['id'],
                'entityType' => $relatedType,
                'entityId' => $option['id'],
                'label' => $option['label'],
                'createdAt' => $link['created_at'],
            ];
        }

        $canLinkMore = !$this->endpointHasMaximumOne($definition, $isSource) || $links === [];
        $candidates = $canLinkMore
            ? array_values(array_filter(
                $this->candidateOptions($relatedType, trim($query), 25),
                fn(array $option): bool =>
                    !in_array($option['id'], $linkedIds, true) &&
                    $this->candidateAcceptsLink($definition, $isSource, $option['id'])
            ))
            : [];

        return [
            'relationship' => [
                'id' => $definition['id'],
                'label' => $isSource ? $definition['label'] : $definition['inverse_label'],
                'relatedEntityType' => $relatedType,
                'relatedEntityLabel' => $this->entityDisplayLabel($relatedType),
                'currentIsSource' => $isSource,
                'canLinkMore' => $canLinkMore,
            ],
            'links' => $links,
            'candidates' => array_slice($candidates, 0, 20),
        ];
    }

    public function unlink(string $linkId): void
    {
        $context = $this->tenantContextStore->require();
        $link = $this->one(
            'SELECT * FROM nexa_custom_relationship_link WHERE id=? AND tenant_id=? AND service_id=? AND deleted_at IS NULL',
            [$this->id($linkId), $context->tenantId, $context->serviceId]
        ) ?? throw new NotFound('Relationship link was not found.');

        $canEdit = false;
        foreach ([
            [$link['source_entity_type'], $link['source_entity_id']],
            [$link['target_entity_type'], $link['target_entity_id']],
        ] as [$type, $id]) {
            try {
                $this->record((string) $type, (string) $id, true);
                $canEdit = true;
                break;
            } catch (Forbidden|NotFound) {
                // The other endpoint can still grant edit authority.
            }
        }
        if (!$canEdit) throw new Forbidden('The relationship is not editable.');

        $statement = $this->entityManager->getPDO()->prepare(
            'UPDATE nexa_custom_relationship_link SET deleted_at=CURRENT_TIMESTAMP(6) WHERE id=? AND tenant_id=? AND service_id=? AND deleted_at IS NULL'
        );
        $statement->execute([$link['id'], $context->tenantId, $context->serviceId]);
        if (!$statement->rowCount()) throw new NotFound('Relationship link was not found.');
        $this->audit('customization.relationship.unlinked', 'relationship', (string) $link['relationship_definition_id'], ['linkId'=>$link['id']]);
    }

    /** @return array<int, array{id:string,label:string}> */
    private function candidateOptions(string $entityType, string $query, int $limit): array
    {
        if (!in_array($entityType, self::NATIVE, true)) {
            $entity = $this->customEntity($entityType);
            $context = $this->tenantContextStore->require();
            $sql = 'SELECT id,display_name FROM nexa_custom_record WHERE tenant_id=? AND service_id=? AND custom_entity_id=? AND deleted_at IS NULL';
            $params = [$context->tenantId, $context->serviceId, $entity['id']];
            if ($query !== '') {
                $sql .= ' AND display_name LIKE ?';
                $params[] = '%' . addcslashes($query, '%_\\') . '%';
            }
            $sql .= ' ORDER BY display_name LIMIT ' . max(1, min(50, $limit));
            return array_map(
                static fn(array $row): array => ['id'=>(string)$row['id'], 'label'=>(string)$row['display_name']],
                $this->all($sql, $params)
            );
        }

        if (!$this->acl->checkScope($entityType, AclTable::ACTION_READ)) return [];
        $builder = $this->selectBuilderFactory->create()->from($entityType)->withStrictAccessControl();
        if ($query !== '') $builder->withTextFilter($query);
        $select = $builder->build();
        $collection = $this->entityManager->getRDBRepository($entityType)->clone($select)->limit(0, $limit)->find();
        $options = [];
        foreach ($collection as $entity) {
            $label = trim((string) $entity->get('name'));
            $options[] = ['id'=>(string)$entity->getId(), 'label'=>$label !== '' ? $label : (string)$entity->getId()];
        }
        return $options;
    }

    /** @return array{id:string,label:string}|null */
    private function recordOption(string $entityType, string $entityId): ?array
    {
        try {
            [$entityType, $entityId] = $this->record($entityType, $entityId, false);
        } catch (Forbidden|NotFound) {
            return null;
        }
        if (!in_array($entityType, self::NATIVE, true)) {
            $context = $this->tenantContextStore->require();
            $row = $this->one(
                'SELECT display_name FROM nexa_custom_record r INNER JOIN nexa_custom_entity_definition e ON e.id=r.custom_entity_id AND e.tenant_id=r.tenant_id AND e.service_id=r.service_id WHERE r.id=? AND r.tenant_id=? AND r.service_id=? AND e.entity_key=? AND r.deleted_at IS NULL',
                [$entityId, $context->tenantId, $context->serviceId, $entityType]
            );
            return $row ? ['id'=>$entityId, 'label'=>(string)$row['display_name']] : null;
        }
        $entity = $this->entityManager->getRDBRepository($entityType)->getById($entityId);
        if (!$entity || !$this->acl->checkEntityRead($entity)) return null;
        $label = trim((string) $entity->get('name'));
        return ['id'=>$entityId, 'label'=>$label !== '' ? $label : $entityId];
    }

    /** @param array<string,mixed> $definition */
    private function endpointHasMaximumOne(array $definition, bool $isSource): bool
    {
        return $isSource
            ? in_array($definition['cardinality'], ['one_to_one','many_to_one'], true)
            : in_array($definition['cardinality'], ['one_to_one','one_to_many'], true);
    }

    /** @param array<string,mixed> $definition */
    private function candidateAcceptsLink(array $definition, bool $currentIsSource, string $candidateId): bool
    {
        if (!$this->endpointHasMaximumOne($definition, !$currentIsSource)) return true;
        $context = $this->tenantContextStore->require();
        $column = $currentIsSource ? 'target_entity_id' : 'source_entity_id';
        return !$this->one(
            "SELECT id FROM nexa_custom_relationship_link WHERE tenant_id=? AND service_id=? AND relationship_definition_id=? AND {$column}=? AND deleted_at IS NULL LIMIT 1",
            [$context->tenantId, $context->serviceId, $definition['id'], $candidateId]
        );
    }

    private function entityDisplayLabel(string $entityType): string
    {
        return match ($entityType) {
            'Contact' => 'Contacts',
            'Account' => 'Accounts',
            default => (string) $this->customEntity($entityType)['plural_label'],
        };
    }

    /** @param array<string, mixed> $definition */
    private function enforceCardinality(array $definition, string $sourceId, string $targetId): void
    {
        $context = $this->tenantContextStore->require();
        $cardinality = $definition['cardinality'];
        $conditions = [];
        $params = [$context->tenantId, $context->serviceId, $definition['id']];

        if (in_array($cardinality, ['one_to_one', 'many_to_one'], true)) {
            $conditions[] = 'source_entity_id=?';
            $params[] = $sourceId;
        }
        if (in_array($cardinality, ['one_to_one', 'one_to_many'], true)) {
            $conditions[] = 'target_entity_id=?';
            $params[] = $targetId;
        }
        if ($conditions === []) return;

        $sql = 'SELECT id FROM nexa_custom_relationship_link WHERE tenant_id=? AND service_id=? AND relationship_definition_id=? AND deleted_at IS NULL AND (' . implode(' OR ', $conditions) . ') AND NOT (source_entity_id=? AND target_entity_id=?) LIMIT 1';
        $params[] = $sourceId;
        $params[] = $targetId;
        if ($this->one($sql, $params)) throw new BadRequest('This relationship would violate its cardinality.');
    }

    /** @param array<string, mixed> $data */
    private function saveEntity(array $data): array
    {
        $context=$this->tenantContextStore->require(); $key=$this->key($data['entityKey']??'','Entity key');
        $icon=$this->iconClass($data['iconClass']??null);
        if (in_array(strtolower($key),array_map('strtolower',self::NATIVE),true)) throw new BadRequest('Native entity names are reserved.');
        $id=isset($data['id'])&&is_string($data['id'])?$this->id($data['id']):$this->uuid(); $label=$this->label($data['label']??'','Label'); $plural=$this->label($data['pluralLabel']??'','Plural label');
        $statement=$this->entityManager->getPDO()->prepare("INSERT INTO nexa_custom_entity_definition (id,tenant_id,service_id,entity_key,label,plural_label,description,icon_class,created_by_id) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),plural_label=VALUES(plural_label),description=VALUES(description),icon_class=VALUES(icon_class),status='active',archived_at=NULL");
        $statement->execute([$id,$context->tenantId,$context->serviceId,$key,$label,$plural,$this->optional($data['description']??null,500),$icon,$this->user->getId()]);
        $this->audit('customization.entity.saved','custom_entity',$id,['entityKey'=>$key]); return ['id'=>$id,'entityKey'=>$key,'label'=>$label,'pluralLabel'=>$plural,'iconClass'=>$icon];
    }

    /** @param array<string, mixed> $data */
    private function saveField(array $data): array
    {
        $context=$this->tenantContextStore->require(); $entityType=$this->entityType($data['entityType']??''); $this->entityExists($entityType); $key=$this->key($data['fieldKey']??'','Field key');
        $type=is_string($data['dataType']??null)?strtolower($data['dataType']):''; if(!in_array($type,self::TYPES,true)) throw new BadRequest('Unsupported custom field type.');
        $options=is_array($data['options']??null)?array_values(array_filter($data['options'],'is_string')):[]; if(in_array($type,['single_select','multi_select'],true)&&$options===[]) throw new BadRequest('Select properties need at least one option.');
        $id=isset($data['id'])&&is_string($data['id'])?$this->id($data['id']):$this->uuid(); $label=$this->label($data['label']??'','Label');
        $editing=isset($data['id']);
        $existing=$this->one('SELECT id,is_active FROM nexa_custom_field_definition WHERE tenant_id=? AND service_id=? AND entity_type=? AND (LOWER(field_key)=LOWER(?) OR LOWER(TRIM(label))=LOWER(TRIM(?))) LIMIT 1',[$context->tenantId,$context->serviceId,$entityType,$key,$label]);
        if($existing&&(!$editing||$existing['id']!==$id)) throw new Conflict(!empty($existing['is_active'])?'A property with this name or internal name already exists. Use the existing property.':'An archived property with this name already exists. Restore it instead of creating a duplicate.');
        foreach($this->standardProperties($entityType) as $standard) if(strcasecmp($standard['field_key'],$key)===0||$this->normalizedLabel($standard['label'])===$this->normalizedLabel($label)) throw new Conflict("{$standard['label']} is already a standard property. Use the existing property.");
        $filterable=array_key_exists('isFilterable',$data)?!empty($data['isFilterable']):true;
        $values=[$label,$this->optional($data['description']??null,500),$type,json_encode($options,JSON_THROW_ON_ERROR),json_encode($data['defaultValue']??null,JSON_THROW_ON_ERROR),json_encode(is_array($data['validation']??null)?$data['validation']:[],JSON_THROW_ON_ERROR),!empty($data['isRequired'])?1:0,!empty($data['isUnique'])?1:0,$filterable?1:0,!empty($data['isSearchable'])?1:0,max(0,(int)($data['position']??0))];
        if($editing){
            if(!$this->one('SELECT id FROM nexa_custom_field_definition WHERE id=? AND tenant_id=? AND service_id=? AND entity_type=?',[$id,$context->tenantId,$context->serviceId,$entityType])) throw new NotFound('Custom property was not found.');
            $statement=$this->entityManager->getPDO()->prepare('UPDATE nexa_custom_field_definition SET label=?,description=?,data_type=?,options_json=?,default_value_json=?,validation_json=?,is_required=?,is_unique=?,is_filterable=?,is_searchable=?,position=?,is_active=1,archived_at=NULL WHERE id=? AND tenant_id=? AND service_id=? AND entity_type=?');
            $statement->execute([...$values,$id,$context->tenantId,$context->serviceId,$entityType]);
        }else{
            $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_custom_field_definition (id,tenant_id,service_id,entity_type,field_key,label,description,data_type,options_json,default_value_json,validation_json,is_required,is_unique,is_filterable,is_searchable,position,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([$id,$context->tenantId,$context->serviceId,$entityType,$key,...$values,$this->user->getId()]);
        }
        $this->audit('customization.field.saved','custom_field',$id,['entityType'=>$entityType,'fieldKey'=>$key]); return ['id'=>$id,'entityType'=>$entityType,'fieldKey'=>$key];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function savePropertyPreference(array $data): array
    {
        $context=$this->tenantContextStore->require();
        $entityType=$this->entityType($data['entityType']??''); $this->entityExists($entityType);
        $fieldKey=$this->propertyKey($data['fieldKey']??'');
        $enabled=!array_key_exists('isEnabled',$data)||!empty($data['isEnabled']);
        $standard=null;
        foreach($this->standardProperties($entityType) as $candidate) if($candidate['field_key']===$fieldKey) {$standard=$candidate; break;}
        $custom=$this->one('SELECT id FROM nexa_custom_field_definition WHERE tenant_id=? AND service_id=? AND entity_type=? AND field_key=? AND is_active=1',[$context->tenantId,$context->serviceId,$entityType,$fieldKey]);
        if(!$standard&&!$custom) throw new NotFound('Property was not found.');
        if(!$enabled&&$standard&&!empty($standard['is_protected'])) throw new BadRequest('This core property is required and cannot be disabled.');
        $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_property_preference (tenant_id,service_id,entity_type,field_key,is_enabled,updated_by_id) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),updated_by_id=VALUES(updated_by_id),updated_at=CURRENT_TIMESTAMP(6)');
        $statement->execute([$context->tenantId,$context->serviceId,$entityType,$fieldKey,$enabled?1:0,$this->user->getId()]);
        $this->audit('customization.property.visibility.updated',$entityType,$fieldKey,['isEnabled'=>$enabled]);
        return ['entityType'=>$entityType,'fieldKey'=>$fieldKey,'isEnabled'=>$enabled];
    }

    /** @return array<int,array<string,mixed>> */
    private function standardProperties(string $entityType, array $preferences=[]): array
    {
        if(!in_array($entityType,self::NATIVE,true)) return [];
        $definitions=$this->metadata->get(['entityDefs',$entityType,'fields'],[]); if(!is_array($definitions)) return [];
        $searchable=$this->metadata->get(['entityDefs',$entityType,'collection','textFilterFields'],[]); if(!is_array($searchable)) $searchable=[];
        $excluded=['id','deleted','tenantId','serviceId','deletedAt','deletedById']; $result=[];
        foreach($definitions as $key=>$definition){
            if(!is_string($key)||!is_array($definition)||in_array($key,$excluded,true)||!empty($definition['disabled'])||!empty($definition['utility'])) continue;
            $type=(string)($definition['type']??'varchar');
            if(in_array($type,['link','linkMultiple','image','file','attachmentMultiple'],true)) continue;
            $label=$this->humanize($key);
            $protected=(bool)($definition['required']??false)||($entityType==='Account'&&$key==='name')||($entityType==='Contact'&&$key==='lastName');
            $preferenceKey=$entityType.':'.$key;
            $result[]=['entity_type'=>$entityType,'field_key'=>$key,'label'=>$label,'data_type'=>$type,'source'=>'standard','is_required'=>(bool)($definition['required']??false),'is_unique'=>(bool)($definition['unique']??false),'is_filterable'=>empty($definition['notStorable']),'is_searchable'=>in_array($key,$searchable,true),'read_only'=>(bool)($definition['readOnly']??false),'is_enabled'=>$protected||($preferences[$preferenceKey]??true),'is_protected'=>$protected];
        }
        usort($result,fn(array $a,array $b):int=>strcasecmp($a['label'],$b['label'])); return $result;
    }

    /** @return array<string,bool> */
    private function propertyPreferenceMap(?string $entityType=null): array
    {
        $context=$this->tenantContextStore->require();
        $sql='SELECT entity_type,field_key,is_enabled FROM nexa_property_preference WHERE tenant_id=? AND service_id=?'.($entityType?' AND entity_type=?':'');
        $params=[$context->tenantId,$context->serviceId]; if($entityType) $params[]=$entityType;
        $result=[]; foreach($this->all($sql,$params) as $row) $result[$row['entity_type'].':'.$row['field_key']]=(bool)$row['is_enabled'];
        return $result;
    }

    private function normalizedLabel(string $value): string { return mb_strtolower((string)preg_replace('/[^a-z0-9]+/i','',trim($value))); }
    private function humanize(string $value): string { return ucfirst(trim((string)preg_replace('/(?<!^)[A-Z]|[_-]+/',' $0',$value))); }

    /** @param array<string, mixed> $data */
    private function saveLayout(array $data): array
    {
        $context=$this->tenantContextStore->require(); $entityType=$this->entityType($data['entityType']??''); $this->entityExists($entityType); $layoutContext=is_string($data['layoutContext']??null)?strtolower($data['layoutContext']):'';
        if(!in_array($layoutContext,['create','edit','detail','list','search'],true)) throw new BadRequest('Unsupported layout context.'); $layout=$data['layout']??null; if(!is_array($layout)) throw new BadRequest('Layout must be a structured array.');
        $known=array_column($this->definitions($entityType)['fields'],'field_key'); foreach($layout as $field) if(!is_string($field)||!in_array($field,$known,true)) throw new BadRequest('Layout contains an unknown custom property.');
        $id=isset($data['id'])&&is_string($data['id'])?$this->id($data['id']):$this->uuid(); $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_custom_layout_definition (id,tenant_id,service_id,entity_type,layout_context,layout_json,created_by_id,updated_by_id) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE layout_json=VALUES(layout_json),version=version+1,updated_by_id=VALUES(updated_by_id)');
        $statement->execute([$id,$context->tenantId,$context->serviceId,$entityType,$layoutContext,json_encode(array_values($layout),JSON_THROW_ON_ERROR),$this->user->getId(),$this->user->getId()]); $this->audit('customization.layout.saved','custom_layout',$id); return ['id'=>$id,'entityType'=>$entityType,'layoutContext'=>$layoutContext,'layout'=>array_values($layout)];
    }

    /** @param array<string, mixed> $data */
    private function saveRelationship(array $data): array
    {
        $context=$this->tenantContextStore->require(); $source=$this->entityType($data['sourceEntityType']??''); $target=$this->entityType($data['targetEntityType']??''); $this->entityExists($source); $this->entityExists($target);
        $cardinality=is_string($data['cardinality']??null)?strtolower($data['cardinality']):''; if(!in_array($cardinality,['one_to_one','one_to_many','many_to_one','many_to_many'],true)) throw new BadRequest('Unsupported relationship cardinality.');
        $id=isset($data['id'])&&is_string($data['id'])?$this->id($data['id']):$this->uuid(); $key=$this->key($data['relationshipKey']??'','Relationship key');
        $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_custom_relationship_definition (id,tenant_id,service_id,relationship_key,label,inverse_label,source_entity_type,target_entity_type,cardinality,is_required,created_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),inverse_label=VALUES(inverse_label),cardinality=VALUES(cardinality),is_required=VALUES(is_required),is_active=1,archived_at=NULL');
        $statement->execute([$id,$context->tenantId,$context->serviceId,$key,$this->label($data['label']??'','Label'),$this->label($data['inverseLabel']??'','Inverse label'),$source,$target,$cardinality,!empty($data['isRequired'])?1:0,$this->user->getId()]); $this->audit('customization.relationship.saved','custom_relationship',$id); return ['id'=>$id,'relationshipKey'=>$key];
    }

    /** @return array{0:string,1:string} */
    private function record(string $entityType,string $entityId,bool $edit): array
    {
        $entityType=$this->entityType($entityType); $entityId=$this->id($entityId);
        if(in_array($entityType,self::NATIVE,true)) { $entity=$this->entityManager->getRDBRepository($entityType)->getById($entityId); if(!$entity||($edit?!$this->acl->checkEntityEdit($entity):!$this->acl->checkEntityRead($entity))) throw new Forbidden('The record is not accessible.'); return [$entityType,$entityId]; }
        $entity=$this->customEntity($entityType); $context=$this->tenantContextStore->require(); if(!$this->one('SELECT id FROM nexa_custom_record WHERE id=? AND tenant_id=? AND service_id=? AND custom_entity_id=? AND deleted_at IS NULL',[$entityId,$context->tenantId,$context->serviceId,$entity['id']])) throw new NotFound('Custom record was not found.'); return [$entityType,$entityId];
    }

    /** @return array<string,mixed> */
    private function customEntity(string $key): array { $context=$this->tenantContextStore->require(); return $this->one("SELECT id,entity_key,label,plural_label,description,icon_class FROM nexa_custom_entity_definition WHERE tenant_id=? AND service_id=? AND entity_key=? AND status='active'",[$context->tenantId,$context->serviceId,$this->entityType($key)])??throw new NotFound('Custom entity was not found.'); }
    private function entityExists(string $type): void { if(!in_array($type,self::NATIVE,true)) $this->customEntity($type); }

    /** @param array<string,mixed> $definition */
    private function writeValue(string $tenant,string $service,string $entityType,string $entityId,array $definition,mixed $value): void
    {
        [$column,$stored]=$this->normalizeValue($definition,$value); if($definition['is_unique']&&!$this->blank($stored)&&$this->one("SELECT entity_id FROM nexa_custom_field_value WHERE tenant_id=? AND service_id=? AND field_definition_id=? AND {$column}=? AND entity_id<>? LIMIT 1",[$tenant,$service,$definition['id'],$stored,$entityId])) throw new BadRequest("{$definition['label']} must be unique.");
        $columns=['value_text'=>null,'value_number'=>null,'value_date'=>null,'value_datetime'=>null,'value_boolean'=>null,'value_json'=>null]; $columns[$column]=$stored;
        $statement=$this->entityManager->getPDO()->prepare('INSERT INTO nexa_custom_field_value (id,tenant_id,service_id,field_definition_id,entity_type,entity_id,value_text,value_number,value_date,value_datetime,value_boolean,value_json,created_by_id,updated_by_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),value_number=VALUES(value_number),value_date=VALUES(value_date),value_datetime=VALUES(value_datetime),value_boolean=VALUES(value_boolean),value_json=VALUES(value_json),updated_by_id=VALUES(updated_by_id)');
        $statement->execute([$this->uuid(),$tenant,$service,$definition['id'],$entityType,$entityId,...array_values($columns),$this->user->getId(),$this->user->getId()]);
    }

    /** @param array<string,mixed> $definition @return array{0:string,1:mixed} */
    private function normalizeValue(array $definition,mixed $value): array
    {
        $type=$definition['data_type']; if($this->blank($value)) return ['value_text',null];
        if(in_array($type,['number','currency'],true)){if(!is_numeric($value))throw new BadRequest("{$definition['label']} must be numeric.");return ['value_number',(string)$value];}
        if($type==='boolean'){ $boolean=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE); if($boolean===null)throw new BadRequest("{$definition['label']} must be true or false."); return ['value_boolean',$boolean?1:0]; }
        if($type==='date'){ $date=\DateTimeImmutable::createFromFormat('!Y-m-d',(string)$value); if(!$date||$date->format('Y-m-d')!==(string)$value)throw new BadRequest("{$definition['label']} must be a valid date."); return ['value_date',(string)$value]; }
        if($type==='datetime'){ $time=strtotime((string)$value); if($time===false)throw new BadRequest("{$definition['label']} must be a valid date and time."); return ['value_datetime',gmdate('Y-m-d H:i:s',$time)]; }
        if($type==='multi_select'){if(!is_array($value))throw new BadRequest("{$definition['label']} must be a list.");foreach($value as $item)if(!is_string($item)||!in_array($item,$definition['options'],true))throw new BadRequest("{$definition['label']} contains an unsupported option.");return ['value_json',json_encode(array_values($value),JSON_THROW_ON_ERROR)];}
        $string=trim((string)$value); if(mb_strlen($string)>65535)throw new BadRequest("{$definition['label']} is too long."); if($type==='single_select'&&!in_array($string,$definition['options'],true))throw new BadRequest("{$definition['label']} contains an unsupported option."); if($type==='email'&&!filter_var($string,FILTER_VALIDATE_EMAIL))throw new BadRequest("{$definition['label']} must be a valid email address."); if($type==='url'&&!filter_var($string,FILTER_VALIDATE_URL))throw new BadRequest("{$definition['label']} must be a valid URL."); return ['value_text',$string];
    }

    /** @param array<string,mixed> $definition @param array<string,mixed>|null $row */
    private function readValue(array $definition,?array $row): mixed { if(!$row)return $definition['defaultValue']; return match($definition['data_type']){'number','currency'=>$row['value_number']!==null?(float)$row['value_number']:null,'date'=>$row['value_date'],'datetime'=>$row['value_datetime'],'boolean'=>$row['value_boolean']!==null?(bool)$row['value_boolean']:null,'multi_select'=>$this->jsonArray($row['value_json']),default=>$row['value_text']}; }
    private function admin(): void { if(!$this->user->isAdmin())throw new Forbidden('Only a tenant administrator can manage customization.'); }
    private function entityType(mixed $value): string { if(!is_string($value)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,63}$/',trim($value)))throw new BadRequest('Invalid entity type.'); foreach(self::NATIVE as $native)if(strcasecmp($native,trim($value))===0)return $native; return strtolower(trim($value)); }
    private function propertyKey(mixed $value): string { if(!is_string($value)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,63}$/',trim($value)))throw new BadRequest('Invalid property key.'); return trim($value); }
    private function iconClass(mixed $value): ?string { if($value===null||$value==='')return null; if(!is_string($value)||!preg_match('/^(?:fas|far|fab|fal) fa-[a-z0-9-]+$/',trim($value)))throw new BadRequest('Choose an icon from the available icon library.'); return trim($value); }
    private function key(mixed $value,string $name): string { if(!is_string($value)||!preg_match('/^[a-z][a-z0-9_]{1,63}$/',strtolower(trim($value))))throw new BadRequest("{$name} must use lowercase letters, numbers and underscores."); return strtolower(trim($value)); }
    private function id(mixed $value): string { if(!is_string($value)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/',trim($value)))throw new BadRequest('Invalid identifier.'); return trim($value); }
    private function label(mixed $value,string $name,int $max=120): string { if(!is_string($value)||trim($value)===''||mb_strlen(trim($value))>$max)throw new BadRequest("{$name} is required and must be at most {$max} characters."); return trim($value); }
    private function optional(mixed $value,int $max): ?string { if($value===null||$value==='')return null; if(!is_string($value)||mb_strlen(trim($value))>$max)throw new BadRequest("Value must be at most {$max} characters."); return trim($value); }
    private function blank(mixed $value): bool { return $value===null||$value===''||(is_array($value)&&$value===[]); }
    /** @return array<int,array<string,mixed>> */ private function all(string $sql,array $params): array { $s=$this->entityManager->getPDO()->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC); }
    /** @return array<string,mixed>|null */ private function one(string $sql,array $params): ?array { $s=$this->entityManager->getPDO()->prepare($sql);$s->execute($params);$row=$s->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null; }
    /** @return array<string|int,mixed> */ private function jsonArray(mixed $value): array { if(!is_string($value)||$value==='')return[];$data=json_decode($value,true);return is_array($data)?$data:[]; }
    private function jsonValue(mixed $value): mixed { return is_string($value)&&$value!==''?json_decode($value,true):null; }
    /** @param array<string,mixed> $metadata */ private function audit(string $action,string $type,string $id,array $metadata=[]): void { $c=$this->tenantContextStore->require();$s=$this->entityManager->getPDO()->prepare("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,source,metadata_json) VALUES (?,?,?,'user',?,?,?,?,'tenant-customization',?)");$s->execute([$this->uuid(),$c->tenantId,$c->serviceId,$this->user->getId(),$action,$type,$id,json_encode($metadata,JSON_THROW_ON_ERROR)]); }
    private function uuid(): string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($b),4)); }
}
