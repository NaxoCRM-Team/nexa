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

    public static function pageUrl(mixed $url): string
    {
        if (!is_string($url) || strlen($url)>2048 || !filter_var($url,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($url,PHP_URL_SCHEME)),['http','https'],true) || preg_match('/[\x00-\x20\x7f]/',$url) || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null || parse_url($url,PHP_URL_FRAGMENT)!==null) throw new BadRequest('Choose an exact HTTP(S) page URL without credentials or a fragment.');
        return $url;
    }

    public function __construct(private EntityManager $em,private TenantContextStore $contexts,private Acl $acl) {}

    public function render(Data $data): Result
    {
        $args=$data->getArgumentList();
        if (!in_array(count($args),[4,5,6],true)) throw new BadRequest('Behavior conditions require a Contact, event, operator, day window and optional count and page URL.');
        [$id,$event,$operator,$days]=$args;
        if (!is_string($event) || !isset(self::EVENTS[$event]) || !in_array($operator,['within_days','not_within_days','count_at_least','count_at_most','count_equals'],true) || !is_string($days) || !preg_match('/^[1-9][0-9]{0,2}$/D',$days) || (int)$days>365) throw new BadRequest('Choose a supported event and a window from 1 to 365 days.');
        $countRule=str_starts_with($operator,'count_');$threshold=$args[4] ?? null;
        if ($countRule && (!is_string($threshold) || !preg_match('/^(?:0|[1-9][0-9]{0,4})$/D',$threshold) || (int)$threshold>10000)) throw new BadRequest('Choose an event count from 0 to 10000.');
        if (!$countRule && !(count($args)===4 || (count($args)===6 && $threshold===''))) throw new BadRequest('Presence conditions cannot include an event count.');
        $pageUrl=null;
        if (count($args)===6) {
            $encoded=$args[5];
            if ($event!=='page.viewed' || !is_string($encoded) || strlen($encoded)>2732 || ($decoded=base64_decode($encoded,true))===false || base64_encode($decoded)!==$encoded) throw new BadRequest('Use a generated website page condition.');
            $pageUrl=self::pageUrl($decoded);
        }
        $total=0;
        $context=$this->contexts->require(); $occurred=false;
        // Syntax validation uses an unsaved native Contact.
        if ($id!==null && $id!=='') {
            if (!is_string($id)) throw new BadRequest('Choose a valid Contact.');
            $contact=$this->em->getRDBRepository('Contact')->getById($id);
            if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId || !$this->acl->checkScope('Contact','read') || !$this->acl->checkEntity($contact,'read')) throw new Forbidden('The behavioral Contact is not accessible.');
            $filter=$pageUrl===null ? '' : ' AND EXISTS (SELECT 1 FROM nexa_behavior_event b WHERE b.id=t.source_entity_id AND b.tenant_id=t.tenant_id AND b.service_id=t.service_id AND b.contact_id=t.contact_id AND b.event_type=t.event_type AND BINARY b.page_url=BINARY ?)';
            $where="t.tenant_id=? AND t.service_id=? AND t.contact_id=? AND t.event_type=? AND t.source_entity_type=? AND t.visibility IN ('internal','team','customer') AND t.source_occurred_at BETWEEN DATE_SUB(UTC_TIMESTAMP(6),INTERVAL ? DAY) AND UTC_TIMESTAMP(6)" . $filter;
            $sql=$countRule ? "SELECT COUNT(*) FROM (SELECT DISTINCT t.source_entity_id FROM nexa_timeline_event t WHERE {$where} AND t.source_entity_id IS NOT NULL LIMIT 10001) AS counted_events" : "SELECT 1 FROM nexa_timeline_event t WHERE {$where} LIMIT 1";
            $query=$this->em->getPDO()->prepare($sql);
            $query->execute([$context->tenantId,$context->serviceId,$id,$event,self::EVENTS[$event],(int)$days,...($pageUrl===null ? [] : [$pageUrl])]);
            $total=(int)$query->fetchColumn();$occurred=$total>0;
        }
        $matched=match($operator){'count_at_least'=>$total>=(int)$threshold,'count_at_most'=>$total<=(int)$threshold,'count_equals'=>$total===(int)$threshold,'within_days'=>$occurred,default=>!$occurred};
        $branch=$matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
