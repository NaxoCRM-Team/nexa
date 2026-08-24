<?php

namespace Espo\Custom\Hooks\Common;

use Espo\Core\Hook\Hook\AfterRelate;
use Espo\Core\Hook\Hook\AfterRemove;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\AfterUnrelate;
use Espo\Custom\Tools\Customer\CustomerFoundationRecorder;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\RelateOptions;
use Espo\ORM\Repository\Option\RemoveOptions;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\Option\UnrelateOptions;

/**
 * Central hook for customer coordination.
 *
 * Keeping this at the ORM boundary covers forms, imports, APIs and background
 * work uniformly; individual pages do not need tenant or event-writing code.
 *
 * @implements AfterSave<Entity>
 * @implements AfterRemove<Entity>
 * @implements AfterRelate<Entity>
 * @implements AfterUnrelate<Entity>
 */
final class CustomerFoundation implements AfterSave, AfterRemove, AfterRelate, AfterUnrelate
{
    public static int $order = 20;

    public function __construct(private CustomerFoundationRecorder $recorder) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $this->recorder->afterSave($entity);
    }

    public function afterRemove(Entity $entity, RemoveOptions $options): void
    {
        $this->recorder->afterRemove($entity);
    }

    public function afterRelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        array $columnData,
        RelateOptions $options,
    ): void {
        $this->recorder->relationshipChanged($entity, $relationName, $relatedEntity, true);
    }

    public function afterUnrelate(
        Entity $entity,
        string $relationName,
        Entity $relatedEntity,
        UnrelateOptions $options,
    ): void {
        $this->recorder->relationshipChanged($entity, $relationName, $relatedEntity, false);
    }
}
