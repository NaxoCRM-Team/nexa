<?php
declare(strict_types=1);
namespace Espo\Custom\Hooks\Common;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
final class PrimaryScoreOwnership implements BeforeSave
{
    public static int $order = 5;
    public function __construct(private EntityManager $em,private TenantContextStore $contexts) {}
    public function beforeSave(Entity $entity,SaveOptions $options): void
    {
        if (!in_array($entity->getEntityType(),['Contact','Account'],true) || !$entity->isAttributeChanged('leadScore')) return;
        if ($entity->isNew() && ($entity->get('leadScore')===null || (int)$entity->get('leadScore')===0)) return;
        $context=$this->contexts->require();
        $tenant=$entity->get('tenantId')??($entity->isNew()?$context->tenantId:null);
        $service=$entity->get('serviceId')??($entity->isNew()?$context->serviceId:null);
        if ($tenant!==$context->tenantId || $service!==$context->serviceId) throw new Forbidden('Score ownership requires the current workspace.');
        if ($this->em->getPDO()->inTransaction()) {
            $lock=$this->em->getPDO()->prepare('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE');$lock->execute([$context->tenantId]);
        }
        $q=$this->em->getPDO()->prepare("SELECT id FROM nexa_scoring_model WHERE tenant_id=? AND service_id=? AND entity_type=? AND is_primary=1 AND status='published'");
        $q->execute([$context->tenantId,$context->serviceId,$entity->getEntityType()]);$modelId=$q->fetchColumn();
        if (!$modelId) return;
        $evaluationId=$options->get('nexaPrimaryScoreEvaluation');
        if (!is_string($evaluationId)) throw new Forbidden('This score is managed by its primary model. Release model ownership before adjusting it.');
        $q=$this->em->getPDO()->prepare('SELECT score FROM nexa_scoring_evaluation WHERE tenant_id=? AND service_id=? AND model_id=? AND id=? AND record_id=?');
        $q->execute([$context->tenantId,$context->serviceId,$modelId,$evaluationId,$entity->getId()]);$score=$q->fetchColumn();
        if ($score===false || (int)$score!==(int)$entity->get('leadScore')) throw new Forbidden('The score does not match its primary model evaluation.');
    }
}
