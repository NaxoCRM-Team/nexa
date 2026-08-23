<?php

namespace Espo\Custom\Classes\Select\Account\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Entities\User;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\SelectBuilder;

/** Limits Account lists to records created by the authenticated user. */
final class CreatedByMe implements Filter
{
    public function __construct(private User $user) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(
            Cond::equal(Cond::column('createdById'), $this->user->getId())
        );
    }
}
