<?php

declare(strict_types=1);

namespace Espo\Custom\Hooks\Case;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Custom\Tools\CaseService\CaseSlaService;
use Espo\Modules\Crm\Entities\CaseObj;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** Extends native Case saves without replacing native portal or email hooks. */
final class CaseSlaLifecycle implements BeforeSave
{
    public static int $order = 15;

    public function __construct(private CaseSlaService $service) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof CaseObj) return;
        if ($entity->isNew()) {
            $this->service->initialize($entity);
            return;
        }
        $this->service->prepareUpdate($entity);
    }
}
