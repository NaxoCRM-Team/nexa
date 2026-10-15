<?php
declare(strict_types=1);
namespace Espo\Custom\Hooks\Account;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\Core\Exceptions\BadRequest;
final class TargetAccount implements BeforeSave
{
    public function beforeSave(Entity $entity,SaveOptions $options): void
    {
        if (($entity->isNew() || $entity->isAttributeChanged('targetAccountTier')) && !in_array($entity->get('targetAccountTier'),['Tier1','Tier2','Tier3'],true)) throw new BadRequest('Choose a valid target account tier.');
    }
}
