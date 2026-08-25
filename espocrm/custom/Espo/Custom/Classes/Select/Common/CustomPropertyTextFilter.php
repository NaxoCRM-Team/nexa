<?php

namespace Espo\Custom\Classes\Select\Common;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Select\Text\DefaultFilter;
use Espo\Core\Select\Text\Filter;
use Espo\Core\Select\Text\Filter\Data;
use Espo\Core\Tenant\TenantContextStore;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Part\Where\OrGroup;
use Espo\ORM\Query\SelectBuilder;

/** Extends native keyword search with tenant-defined searchable property values. */
final class CustomPropertyTextFilter implements Filter
{
    public function __construct(
        private string $entityType,
        private DefaultFilter $defaultFilter,
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
    ) {}

    public function apply(SelectBuilder $queryBuilder, Data $data): void
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id,data_type FROM nexa_custom_field_definition WHERE tenant_id=? AND service_id=? AND entity_type=? AND is_active=1 AND is_searchable=1'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $this->entityType]);
        $definitions = $statement->fetchAll(\PDO::FETCH_ASSOC);

        if (!$definitions) {
            $this->defaultFilter->apply($queryBuilder, $data);
            return;
        }

        // Build the native clause separately so it can be OR-ed with custom values.
        $nativeBuilder = SelectBuilder::create()->from($this->entityType);
        $this->defaultFilter->apply($nativeBuilder, $data);
        $nativeQuery = $nativeBuilder->build();
        foreach ($nativeQuery->getJoins() as $join) if (!$queryBuilder->hasJoinAlias($join->getAlias() ?? (string)$join->getTarget())) $queryBuilder->join($join);
        foreach ($nativeQuery->getLeftJoins() as $join) if (!$queryBuilder->hasLeftJoinAlias($join->getAlias() ?? (string)$join->getTarget())) $queryBuilder->leftJoin($join);

        $valueBuilder = SelectBuilder::create()
            ->from('NexaCustomFieldValue', 'customValue')
            ->select('customValue.entityId')
            ->where(Cond::equal(Expr::column('customValue.tenantId'), $context->tenantId))
            ->where(Cond::equal(Expr::column('customValue.serviceId'), $context->serviceId))
            ->where(Cond::equal(Expr::column('customValue.entityType'), $this->entityType))
            ->where(Cond::in(Expr::column('customValue.fieldDefinitionId'), array_column($definitions, 'id')));

        $filter = trim($data->getFilter());
        $valueMatches = OrGroup::createBuilder()
            ->add(Cond::like(Expr::column('customValue.valueText'), '%' . $filter . '%'))
            ->add(Cond::like(Expr::column('customValue.valueJson'), '%' . $filter . '%'));
        if (is_numeric($filter)) $valueMatches->add(Cond::equal(Expr::column('customValue.valueNumber'), (float)$filter));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter)) $valueMatches->add(Cond::equal(Expr::column('customValue.valueDate'), $filter));
        $valueBuilder->where($valueMatches->build());

        $matches = OrGroup::createBuilder();
        if ($nativeQuery->getWhere()) $matches->add($nativeQuery->getWhere());
        $matches->add(Cond::in(Expr::column('id'), $valueBuilder->build()));
        $queryBuilder->where($matches->build());
    }
}
