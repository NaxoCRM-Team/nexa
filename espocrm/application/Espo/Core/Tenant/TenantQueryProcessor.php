<?php

namespace Espo\Core\Tenant;

use Espo\Core\Tenant\Exception\TenantScopeViolation;
use Espo\ORM\Executor\QueryProcessor;
use Espo\ORM\Metadata;
use Espo\ORM\Query\Delete;
use Espo\ORM\Query\Insert;
use Espo\ORM\Query\Query;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectingQuery;
use Espo\ORM\Query\Union;
use Espo\ORM\Query\Update;

final class TenantQueryProcessor implements QueryProcessor
{
    public function __construct(
        private TenantContextStore $contextStore,
        private EntityOwnershipRegistry $ownership,
        private Metadata $metadata,
    ) {}

    public function process(Query $query): Query
    {
        if ($this->contextStore->isPlatform()) {
            return $query;
        }

        return match (true) {
            $query instanceof Select => $this->processSelect($query),
            $query instanceof Update => $this->processWrite($query, $query->getIn()),
            $query instanceof Delete => $this->processWrite($query, $query->getFrom()),
            $query instanceof Insert => $this->processInsert($query),
            $query instanceof Union => $this->processUnion($query),
            default => $query,
        };
    }

    private function processSelect(Select $query): Select
    {
        $raw = $query->getRaw();

        if (($raw['fromQuery'] ?? null) instanceof SelectingQuery) {
            $raw['fromQuery'] = $this->process($raw['fromQuery']);
        }

        $entityType = $query->getFrom();

        if ($entityType !== null) {
            $this->assertKnown($entityType);

            if ($this->ownership->isTenantEntity($entityType)) {
                $alias = $query->getFromAlias();
                $prefix = $alias ? $alias . '.' : '';
                $raw['whereClause'] = $this->addOwnershipScope($raw['whereClause'] ?? [], $prefix);
            }

            $raw = $this->scopeJoins($raw, $entityType);
        }

        return Select::fromRaw($raw);
    }

    private function processWrite(Update|Delete $query, string $entityType): Update|Delete
    {
        $this->assertKnown($entityType);

        if ($this->ownership->isPlatformEntity($entityType)) {
            throw new TenantScopeViolation("Platform table '{$entityType}' cannot be changed in a tenant request.");
        }

        $raw = $query->getRaw();
        $alias = $raw['fromAlias'] ?? null;
        $raw['whereClause'] = $this->addOwnershipScope(
            $raw['whereClause'] ?? [],
            $alias ? $alias . '.' : ''
        );
        $raw = $this->scopeJoins($raw, $entityType);

        if ($query instanceof Update && $this->containsOwnershipKey($raw['set'] ?? [])) {
            throw new TenantScopeViolation('Tenant and service identifiers cannot be changed.');
        }

        return $query instanceof Update ? Update::fromRaw($raw) : Delete::fromRaw($raw);
    }

    private function processInsert(Insert $query): Insert
    {
        $raw = $query->getRaw();
        $entityType = $raw['into'];
        $this->assertKnown($entityType);

        if ($this->ownership->isPlatformEntity($entityType)) {
            throw new TenantScopeViolation("Platform table '{$entityType}' cannot be changed in a tenant request.");
        }

        $columns = $raw['columns'] ?? [];

        if (in_array('tenantId', $columns, true) || in_array('serviceId', $columns, true)) {
            throw new TenantScopeViolation('Callers cannot provide tenant or service identifiers.');
        }

        $context = $this->contextStore->require();
        $raw['columns'][] = 'tenantId';
        $raw['columns'][] = 'serviceId';

        if (isset($raw['valuesQuery'])) {
            $raw['valuesQuery'] = $this->addOwnershipProjection($raw['valuesQuery'], $context);

            return Insert::fromRaw($raw);
        }
        $isMass = isset($raw['values'][0]) && is_array($raw['values'][0]);

        if ($isMass) {
            foreach ($raw['values'] as &$values) {
                $values['tenantId'] = $context->tenantId;
                $values['serviceId'] = $context->serviceId;
            }
            unset($values);
        } else {
            $raw['values']['tenantId'] = $context->tenantId;
            $raw['values']['serviceId'] = $context->serviceId;
        }

        if ($this->containsOwnershipKey($raw['updateSet'] ?? [])) {
            throw new TenantScopeViolation('Tenant and service identifiers cannot be changed.');
        }

        return Insert::fromRaw($raw);
    }

    private function addOwnershipProjection(SelectingQuery $query, TenantContext $context): Select
    {
        if (!$query instanceof Select) {
            throw new TenantScopeViolation('Tenant-owned INSERT SELECT requires a scoped SELECT query.');
        }

        $query = $this->processSelect($query);
        $raw = $query->getRaw();
        $raw['select'] = $raw['select'] ?? [];
        $raw['select'][] = ["VALUE:{$context->tenantId}", 'tenantId'];
        $raw['select'][] = ["VALUE:{$context->serviceId}", 'serviceId'];

        return Select::fromRaw($raw);
    }

    private function processUnion(Union $query): Union
    {
        $raw = $query->getRaw();

        foreach ($raw['queries'] as &$item) {
            if ($item instanceof Query) {
                $item = $this->process($item);
            }
        }
        unset($item);

        return Union::fromRaw($raw);
    }

    /** @param array<string, mixed> $raw @return array<string, mixed> */
    private function scopeJoins(array $raw, string $rootEntityType): array
    {
        foreach (['joins', 'leftJoins'] as $joinKey) {
            foreach ($raw[$joinKey] ?? [] as $index => $join) {
                $wasString = is_string($join);
                $item = $wasString ? [$join] : $join;
                $target = $item[0] ?? null;

                if ($target instanceof Select) {
                    $item[0] = $this->processSelect($target);
                } elseif (is_string($target)) {
                    $isTable = ucfirst($target) === $target;
                    $foreignType = $isTable
                        ? $target
                        : $this->metadata->getDefs()->getEntity($rootEntityType)
                            ->tryGetRelation($target)?->tryGetForeignEntityType();

                    if ($foreignType && $this->ownership->isTenantEntity($foreignType) && $isTable) {
                        $alias = $item[1] ?? $target;
                        $item[2] = $this->addOwnershipScope($item[2] ?? [], $alias . '.');
                    }
                }

                $raw[$joinKey][$index] = $wasString ? $join : $item;
            }
        }

        return $raw;
    }

    /** @param array<string|int, mixed> $where @return array<string|int, mixed> */
    private function addOwnershipScope(array $where, string $prefix = ''): array
    {
        $context = $this->contextStore->require();
        $scope = [
            $prefix . 'tenantId' => $context->tenantId,
            $prefix . 'serviceId' => $context->serviceId,
        ];

        if ($where === []) {
            return $scope;
        }

        $where[] = $scope;

        return $where;
    }

    /** @param array<string, mixed> $values */
    private function containsOwnershipKey(array $values): bool
    {
        foreach (array_keys($values) as $key) {
            $key = rtrim((string) $key, ':');

            if ($key === 'tenantId' || $key === 'serviceId') {
                return true;
            }
        }

        return false;
    }

    private function assertKnown(string $entityType): void
    {
        if (!$this->ownership->isKnownEntity($entityType)) {
            throw new TenantScopeViolation("Entity '{$entityType}' has no tenant ownership classification.");
        }
    }
}
