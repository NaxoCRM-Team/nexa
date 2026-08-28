<?php

declare(strict_types=1);

namespace Espo\Custom\Hooks\Opportunity;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Custom\Tools\Sales\SalesWorkspaceService;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** Keeps forecast math and revenue history consistent across every Opportunity write path. */
final class SalesLifecycle implements BeforeSave, AfterSave
{
    public static int $order = 15;
    public function __construct(private SalesWorkspaceService $service) {}
    public function beforeSave(Entity $entity, SaveOptions $options): void { $this->service->prepareOpportunity($entity); }
    public function afterSave(Entity $entity, SaveOptions $options): void { $this->service->recordOpportunity($entity); }
}
