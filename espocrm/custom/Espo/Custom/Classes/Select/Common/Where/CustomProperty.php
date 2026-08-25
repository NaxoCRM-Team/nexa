<?php

namespace Espo\Custom\Classes\Select\Common\Where;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Select\Where\Item;
use Espo\Core\Select\Where\ItemConverter;
use Espo\Core\Tenant\TenantContextStore;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\WhereItem;
use Espo\ORM\Query\SelectBuilder;

/** Converts the dynamic property filter into a tenant-scoped value subquery. */
final class CustomProperty implements ItemConverter
{
    public function __construct(
        private string $entityType,
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
    ) {}

    public function convert(SelectBuilder $queryBuilder, Item $item): WhereItem
    {
        $payload = $item->getValue();
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (!is_array($payload)) throw new BadRequest('Custom property filter is invalid.');
        $fieldKey = is_string($payload['fieldKey'] ?? null) ? $payload['fieldKey'] : '';
        $operator = is_string($payload['operator'] ?? null) ? $payload['operator'] : 'equals';
        $value = $payload['value'] ?? null;
        $context = $this->tenantContextStore->require();

        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id,data_type FROM nexa_custom_field_definition WHERE tenant_id=? AND service_id=? AND entity_type=? AND field_key=? AND is_active=1 AND is_filterable=1 LIMIT 1'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $this->entityType, $fieldKey]);
        $definition = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$definition) throw new BadRequest('The selected custom property cannot be filtered.');

        [$column, $normalized] = $this->columnAndValue((string)$definition['data_type'], $value);
        $subQuery = SelectBuilder::create()
            ->from('NexaCustomFieldValue', 'customValue')
            ->select('customValue.entityId')
            ->where(Cond::equal(Expr::column('customValue.tenantId'), $context->tenantId))
            ->where(Cond::equal(Expr::column('customValue.serviceId'), $context->serviceId))
            ->where(Cond::equal(Expr::column('customValue.entityType'), $this->entityType))
            ->where(Cond::equal(Expr::column('customValue.fieldDefinitionId'), (string)$definition['id']));
        $field = Expr::column('customValue.' . $column);

        if ($operator === 'empty' || $operator === 'not_empty') {
            $subQuery->where(Cond::notEqual($field, null));
            if (in_array($column, ['valueText','valueJson'], true)) $subQuery->where(Cond::notEqual($field, ''));
            return $operator === 'empty' ? Cond::notIn(Expr::column('id'), $subQuery->build()) : Cond::in(Expr::column('id'), $subQuery->build());
        }
        if ($normalized === null || $normalized === '') throw new BadRequest('Enter a value for the custom property filter.');

        $isMultiSelect = $definition['data_type'] === 'multi_select';
        $condition = match (true) {
            $isMultiSelect && in_array($operator, ['equals', 'not_equals', 'contains'], true) => Cond::like($field, '%' . (string)$normalized . '%'),
            $operator === 'contains' => Cond::like($field, '%' . (string)$normalized . '%'),
            $operator === 'greater_than' => Cond::greater($field, $normalized),
            $operator === 'greater_or_equal' => Cond::greaterOrEqual($field, $normalized),
            $operator === 'less_than' => Cond::less($field, $normalized),
            $operator === 'less_or_equal' => Cond::lessOrEqual($field, $normalized),
            in_array($operator, ['equals', 'not_equals'], true) => Cond::equal($field, $normalized),
            default => throw new BadRequest('Unsupported custom property filter operator.'),
        };
        $subQuery->where($condition);
        return $operator === 'not_equals' ? Cond::notIn(Expr::column('id'), $subQuery->build()) : Cond::in(Expr::column('id'), $subQuery->build());
    }

    /** @return array{0:string,1:mixed} */
    private function columnAndValue(string $type, mixed $value): array
    {
        return match ($type) {
            'number', 'currency' => ['valueNumber', is_numeric($value) ? (float)$value : $value],
            'date' => ['valueDate', $value],
            'datetime' => ['valueDatetime', $value],
            'boolean' => ['valueBoolean', filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)],
            'multi_select' => ['valueJson', is_string($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value],
            default => ['valueText', is_scalar($value) ? trim((string)$value) : null],
        };
    }
}
