<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\LandingPage;

use Espo\Core\{Acl,InjectableFactory};
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\User;
use Espo\Custom\Tools\Event\BehaviorEventService;
use Espo\Custom\Tools\Consent\CookieConsentService;
use Espo\Custom\Tools\PublicAccess\PublicRequestLimiter;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\BehaviorCondition;
use Espo\Core\Htmlizer\Helper\Data;
use PDO;
use stdClass;

/** Authenticated website-backend bridge; never a public Contact-ID lookup. */
final class CustomerPersonalizationService
{
    public function __construct(private EntityManager $em,private TenantContextStore $contexts,
        private User $user,private Acl $acl,private ServiceFactory $records,private InjectableFactory $factory,
        private BehaviorEventService $events,private CookieConsentService $cookies,private LandingPageService $pages,
        private PublicRequestLimiter $limiter) {}

    public function identify(stdClass $input): array
    {
        $this->access('identify');
        if(array_diff(array_keys((array)$input),['visitorKey','contactId','method','reference','privacyControl']))throw new BadRequest('Unsupported identity proof settings.');
        $key=$this->visitorKey($input->visitorKey??null);$method=$input->method??null;$reference=$input->reference??null;
        if(!in_array($method,['authenticated_session','verified_email','oauth_subject'],true) || !is_string($reference) || trim($reference)==='' || strlen($reference)>500)throw new BadRequest('Provide backend-verified identity evidence.');
        $contact=$this->contact($input->contactId??null);
        if(!$contact)throw new NotFound('Contact unavailable.');
        if(isset($input->privacyControl)&&!is_bool($input->privacyControl))throw new BadRequest('Privacy control must be a boolean.');
        if(($input->privacyControl??false)||!$this->cookies->allowsPersonalization($key))return ['verified'=>false,'validForSeconds'=>0];
        $context=$this->contexts->require();$evidence=hash('sha256',$method."\0".$reference);
        $pdo=$this->em->getPDO();$owns=!$pdo->inTransaction();if($owns)$pdo->beginTransaction();else $pdo->exec('SAVEPOINT nexa_personalization_identity');
        try{
            $lock=$pdo->prepare('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE');$lock->execute([$context->tenantId]);
            $event=$this->events->ingest((object)['eventType'=>'custom.personalization_verified','consentCategory'=>'necessary','source'=>'personalization-backend',
                'idempotencyKey'=>hash('sha256',$this->user->getId()."\0".$key."\0".$contact->getId()."\0".$evidence),
                'visitorKey'=>$key,'contactId'=>$contact->getId(),'identityEvidence'=>(object)['verified'=>true,'type'=>$method,'reference'=>$reference]]);
            $visitor=$pdo->prepare('SELECT id FROM nexa_visitor_identity WHERE tenant_id=? AND service_id=? AND visitor_key_hash=? AND contact_id=?');
            $visitor->execute([$context->tenantId,$context->serviceId,$this->hashKey($key),$contact->getId()]);$id=$visitor->fetchColumn();
            if(!$id)throw new BadRequest('The visitor identity could not be verified.');
            $save=$pdo->prepare('INSERT INTO nexa_personalization_identity_proof (tenant_id,service_id,visitor_identity_id,issuer_user_id,contact_id,proof_method,evidence_hash,verification_event_id,verified_at,expires_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) ON DUPLICATE KEY UPDATE contact_id=VALUES(contact_id),proof_method=VALUES(proof_method),evidence_hash=VALUES(evidence_hash),verification_event_id=VALUES(verification_event_id),verified_at=VALUES(verified_at),expires_at=VALUES(expires_at),revoked_at=NULL');
            $save->execute([$context->tenantId,$context->serviceId,$id,$this->user->getId(),$contact->getId(),$method,$evidence,$event['id']]);
            if($owns)$pdo->commit();else $pdo->exec('RELEASE SAVEPOINT nexa_personalization_identity');
            return ['verified'=>true,'validForSeconds'=>86400];
        }catch(\Throwable $error){if($owns&&$pdo->inTransaction())$pdo->rollBack();elseif(!$owns)$pdo->exec('ROLLBACK TO SAVEPOINT nexa_personalization_identity');throw $error;}
    }

    public function revoke(stdClass $input): array
    {
        $this->access('revoke');if(array_diff(array_keys((array)$input),['visitorKey']))throw new BadRequest('Unsupported revocation settings.');
        $key=$this->visitorKey($input->visitorKey??null);$context=$this->contexts->require();
        $query=$this->em->getPDO()->prepare('UPDATE nexa_personalization_identity_proof p INNER JOIN nexa_visitor_identity v ON v.id=p.visitor_identity_id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id SET p.revoked_at=UTC_TIMESTAMP(6) WHERE p.tenant_id=? AND p.service_id=? AND p.issuer_user_id=? AND v.visitor_key_hash=? AND p.revoked_at IS NULL');
        $query->execute([$context->tenantId,$context->serviceId,$this->user->getId(),$this->hashKey($key)]);
        return ['revoked'=>true];
    }

    public function resolve(stdClass $input): array
    {
        $this->access('resolve');
        if(array_diff(array_keys((array)$input),['pageId','visitorKey','privacyControl','context']))throw new BadRequest('Resolve a visitor through backend proof, not browser CRM identifiers.');
        $key=$this->visitorKey($input->visitorKey??null);$id=$input->pageId??null;
        if(!is_string($id)||!preg_match('/^[a-f0-9-]{36}$/iD',$id))throw new BadRequest('Choose a published page.');
        if(isset($input->privacyControl)&&!is_bool($input->privacyControl))throw new BadRequest('Privacy control must be a boolean.');
        $context=$this->contexts->require();$query=$this->em->getPDO()->prepare("SELECT p.*,v.configuration_json FROM nexa_landing_page p INNER JOIN nexa_landing_page_version v ON v.landing_page_id=p.id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.version_number=p.version_number WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND p.status='published'");
        $query->execute([$id,$context->tenantId,$context->serviceId]);$page=$query->fetch(PDO::FETCH_ASSOC);if(!$page)throw new NotFound('Published page unavailable.');
        $configuration=json_decode($page['configuration_json'],true);$gpc=$input->privacyControl??false;
        $proof=$gpc?null:$this->proof($key);$contact=$proof?$this->contact($proof['contact_id']):null;
        $eligible=$contact!==null && $this->cookies->allowsPersonalization($key);$decisions=[];$blocks=[];
        $requestContext=isset($input->context)&&$input->context instanceof stdClass?(array)$input->context:[];
        foreach($configuration['blocks']??[] as $block){
            $rule=$block['personalization']??null;
            if(is_array($rule)&&str_starts_with($rule['field'],'customer:')){
                $value=null;$allowed=$eligible;$behavior=null;
                if($allowed){
                    $field=substr($rule['field'],9);
                    if(in_array($field,['lifecycleStage','leadStatus','leadScore'],true)){
                        $allowed=$this->acl->checkField('Contact',$field,'read');$value=$allowed?$contact->get($field):null;
                    }elseif($field==='segment'){
                        try{$list=$this->records->createForUser('TargetList',$this->user)->getEntity($rule['value']);}catch(Forbidden|NotFound){$list=null;}
                        $allowed=$this->acl->checkScope('TargetList','read')&&$list&&$list->get('tenantId')===$context->tenantId&&$list->get('serviceId')===$context->serviceId&&$this->acl->checkEntity($list,'read');
                        if($allowed){$member=$this->em->getPDO()->prepare("SELECT 1 FROM contact_target_list m INNER JOIN nexa_segment_definition s ON s.target_list_id=m.target_list_id AND s.tenant_id=m.tenant_id AND s.service_id=m.service_id WHERE m.tenant_id=? AND m.service_id=? AND m.contact_id=? AND m.target_list_id=? AND m.deleted=0 AND COALESCE(m.opted_out,0)=0 AND s.status='active'");$member->execute([$context->tenantId,$context->serviceId,$contact->getId(),$rule['value']]);$value=$member->fetchColumn()?$rule['value']:null;}
                    }elseif($field==='visits30'){
                        $threshold=(int)$rule['value'];$operator=$rule['operator']??'equals';
                        if($operator==='less_than'&&$threshold===0)$behavior=false;
                        else {
                            $comparison=match($operator){'greater_than'=>'count_at_least','less_than'=>'count_at_most',default=>'count_equals'};
                            $count=$threshold+($operator==='greater_than'?1:($operator==='less_than'?-1:0));
                            $helper=$this->factory->createWith(BehaviorCondition::class,['acl'=>$this->acl]);
                            $result=$helper->render(new Data('nexaEmailBehavior',[$contact->getId(),'page.viewed',$comparison,'30',(string)$count],new stdClass(),null,[],fn()=>'matched',fn()=>'fallback'));
                            $behavior=(string)$result->getValue()==='matched';
                        }
                    }else $allowed=false;
                }
                $decision=LandingPagePersonalization::customerDecision($block,$value,(bool)$allowed,$behavior);
            }else $decision=LandingPagePersonalization::decide($block,$requestContext+['language'=>$configuration['locale']??'en'],$gpc);
            if($decision['evidence'])$decisions[]=$decision['evidence'];
            $resolved=$decision['block'];$blocks[]=['id'=>$resolved['id'],'heading'=>$resolved['heading']??'','text'=>$resolved['text']??''];
        }
        // No raw profile values, visitor key, Contact ID or verification reference leave this adapter.
        if($decisions)$this->pages->recordPersonalization($page,$decisions,$this->user->getId(),['deliveryMode'=>'backend','contactId'=>$eligible?$contact->getId():null,'eligibility'=>$gpc?'privacy_control':($eligible?'eligible':'default_only')]);
        return ['pageId'=>$page['id'],'version'=>(int)$page['version_number'],'blocks'=>$blocks];
    }

    private function proof(string $key): ?array
    {
        $context=$this->contexts->require();$query=$this->em->getPDO()->prepare("SELECT p.contact_id FROM nexa_personalization_identity_proof p INNER JOIN nexa_visitor_identity v ON v.id=p.visitor_identity_id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.contact_id=p.contact_id WHERE p.tenant_id=? AND p.service_id=? AND p.issuer_user_id=? AND v.visitor_key_hash=? AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6) AND p.proof_method IN ('authenticated_session','verified_email','oauth_subject')");
        $query->execute([$context->tenantId,$context->serviceId,$this->user->getId(),$this->hashKey($key)]);return $query->fetch(PDO::FETCH_ASSOC)?:null;
    }

    private function contact(mixed $id): ?\Espo\ORM\Entity
    {
        if(!is_string($id)||$id===''||strlen($id)>24)return null;
        try{$record=$this->records->createForUser('Contact',$this->user)->getEntity($id);}catch(Forbidden|NotFound){return null;}
        $context=$this->contexts->require();return $record && !$record->get('deleted') && $record->get('tenantId')===$context->tenantId && $record->get('serviceId')===$context->serviceId && $this->acl->checkEntity($record,'read')?$record:null;
    }

    private function access(string $operation): void
    {
        $context=$this->contexts->require();
        if(!$this->user->isActive()||!in_array($this->user->getType(),['admin','api'],true)||$this->user->get('tenantId')!==$context->tenantId||$this->user->get('serviceId')!==$context->serviceId||!$this->acl->checkScope('Contact','read'))throw new Forbidden('An authorized website backend is required.');
        $this->limiter->enforce($context->tenantId,$context->serviceId,'personalization-'.$operation.':'.$this->user->getId(),120,60);
    }
    private function visitorKey(mixed $key): string {if(!is_string($key)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD',$key))throw new BadRequest('Provide the verified session visitor UUID.');return strtolower($key);}
    private function hashKey(string $key): string {$context=$this->contexts->require();return hash('sha256',$context->tenantId."\0".$context->serviceId."\0".$key);}
}
