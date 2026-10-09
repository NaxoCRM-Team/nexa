<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data,Result};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;

final class BehaviorCondition implements Helper
{
    public const EVENTS = ['page.viewed'=>'BehaviorEvent','email.opened'=>'MarketingDeliveryEvent','email.clicked'=>'MarketingDeliveryEvent','email.replied'=>'BehaviorEvent'];

    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private Acl $acl) {}

    public function render(Data $data): Result
    {
        $args=$data->getArgumentList();
        if (count($args)!==4) throw new BadRequest('Behavior conditions require a Contact, event, operator and day window.');
        [$id,$event,$operator,$days]=$args;
        if (!is_string($event) || !isset(self::EVENTS[$event]) || !in_array($operator,['within_days','not_within_days'],true) || !is_string($days) || !preg_match('/^[1-9][0-9]{0,2}$/D',$days) || (int)$days>365) throw new BadRequest('Choose a supported event and a window from 1 to 365 days.');
        $context=$this->contexts->require(); $occurred=false;
        // Syntax validation uses an unsaved native Contact.
        if ($id!==null && $id!=='') {
            if (!is_string($id)) throw new BadRequest('Choose a valid Contact.');
            $contact=$this->em->getRDBRepository('Contact')->getById($id);
            if (!$contact || !$this->acl->checkScope('Contact','read') || !$this->acl->checkEntity($contact,'read')) throw new Forbidden('The behavioral Contact is not accessible.');
            $query=$this->em->getPDO()->prepare("SELECT 1 FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type=? AND source_entity_type=? AND visibility IN ('internal','team','customer') AND source_occurred_at BETWEEN DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY) AND UTC_TIMESTAMP(6) LIMIT 1");
            $query->execute([$context->tenantId,$context->serviceId,$id,$event,self::EVENTS[$event],(int)$days]);
            $occurred=(bool)$query->fetchColumn();
        }
        $matched=$operator==='within_days' ? $occurred : !$occurred;
        $branch=$matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
