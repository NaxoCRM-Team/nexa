<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Record\{ServiceFactory,UpdateParams};
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use PDO;

/** Explicit native adapters, not a generic database-write action. */
final class WorkflowRecordActions
{
    public const ACTIONS=['score.increase','score.decrease','tags.add','tags.remove','contact.update','account.update','opportunity.update','team.notify'];
    private const PROPERTIES=[
        'Contact'=>['firstName','lastName','title','department','contactSource','leadStatus','customerStage'],
        'Account'=>['name','industry','type','lifecycleStage','leadStatus','description'],
        'Opportunity'=>['name','stage','closeDate','description'],
    ];

    public function __construct(private EntityManager $em,private ServiceFactory $records,private AclManager $aclManager,private TenantContextStore $contexts,private Metadata $metadata) {}

    public function properties(User $actor): array
    {
        $acl=$this->aclManager->createUserAcl($actor);$result=[];
        foreach(self::PROPERTIES as $type=>$names) {
            $result[$type]=[];
            if(!$acl->checkScope($type,'edit'))continue;
            foreach($names as $name) {
                $definition=$this->metadata->get(['entityDefs',$type,'fields',$name],[]);
                if(!$definition || !empty($definition['readOnly']) || !$acl->checkField($type,$name,'edit') || !$acl->checkField($type,$name,'read'))continue;
                $result[$type][]=['name'=>$name,'type'=>$definition['type'],'options'=>$definition['options'] ?? []];
            }
        }
        return $result;
    }

    public function validate(array $node,User $actor): void
    {
        $action=$node['action'];$settings=$node['settings'];$acl=$this->aclManager->createUserAcl($actor);
        if(in_array($action,['score.increase','score.decrease','tags.add','tags.remove'],true)) {
            $field=str_starts_with($action,'score.')?'leadScore':'tags';
            $score=str_starts_with($action,'score.');
            if(!$acl->checkScope('Contact','edit') || !$acl->checkField('Contact',$field,'read') || ($score?$actor->getType()!==User::TYPE_ADMIN:!$acl->checkField('Contact',$field,'edit')))throw new Forbidden('Contact property access is required.');
            return;
        }
        if($action==='team.notify') {
            $team=$this->records->createForUser('Team',$actor)->getEntity($settings['teamId']);$this->owned($team);
            if(!$acl->checkAssignmentPermission($team))throw new Forbidden('Team assignment access is required.');
            return;
        }
        $type=match($action){'contact.update'=>'Contact','account.update'=>'Account','opportunity.update'=>'Opportunity',default=>throw new BadRequest('Unsupported record adapter.')};
        $catalog=array_column($this->properties($actor)[$type],null,'name');
        if(!isset($catalog[$settings['field']]))throw new Forbidden('This property is not approved for workflow updates.');
        $field=$catalog[$settings['field']];$value=$settings['value'];
        if($field['type']==='enum' && !in_array($value,$field['options'],true))throw new BadRequest('Choose a configured property option.');
        if($field['type']==='date')\Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::canonical($value);
        if($action==='opportunity.update')$this->owned($this->records->createForUser('Opportunity',$actor)->getEntity($settings['opportunityId']));
    }

    public function execute(array $node,Entity $contact,User $actor): array
    {
        $this->validate($node,$actor);$action=$node['action'];$settings=$node['settings'];
        if($action==='team.notify') {
            $query=$this->em->getPDO()->prepare("SELECT u.id FROM user u INNER JOIN team_user m ON m.user_id=u.id AND m.tenant_id=u.tenant_id AND m.service_id=u.service_id AND m.deleted=0 AND m.team_id=? WHERE u.tenant_id=? AND u.service_id=? AND u.deleted=0 AND u.is_active=1 AND u.type IN ('admin','regular') ORDER BY u.id LIMIT 201");
            $query->execute([$settings['teamId'],...$this->scope()]);$ids=$query->fetchAll(PDO::FETCH_COLUMN);
            if(count($ids)>200)throw new BadRequest('Team notifications support at most 200 active members per step.');
            $count=0;
            foreach($ids as $id) {
                $recipient=$this->em->getEntityById('User',$id);
                if(!$recipient instanceof User || !$this->aclManager->createUserAcl($recipient)->check($contact,'read'))continue;
                $this->em->createEntity('Notification',['type'=>'System','userId'=>$id,'message'=>$settings['message'],'relatedType'=>'Contact','relatedId'=>$contact->getId(),'createdById'=>$actor->getId()],[SaveOption::CREATED_BY_ID=>$actor->getId()]);$count++;
            }
            return ['action'=>$action,'teamId'=>$settings['teamId'],'recipientCount'=>$count];
        }
        $type='Contact';$id=$contact->getId();
        if($action==='account.update') {
            $type='Account';$id=(string)$contact->get('accountId');
            if($id==='')throw new NotFound('The Contact has no primary Account.');
        }
        if($action==='opportunity.update') {
            $type='Opportunity';$id=$settings['opportunityId'];
            $query=$this->em->getPDO()->prepare('SELECT o.id FROM opportunity o WHERE o.id=? AND o.tenant_id=? AND o.service_id=? AND o.deleted=0 AND (o.contact_id=? OR EXISTS (SELECT 1 FROM contact_opportunity r WHERE r.opportunity_id=o.id AND r.contact_id=? AND r.deleted=0 AND r.tenant_id=o.tenant_id AND r.service_id=o.service_id))');
            $query->execute([$id,...$this->scope(),$contact->getId(),$contact->getId()]);
            if(!$query->fetchColumn())throw new Forbidden('The Opportunity is not associated with this Contact.');
        }
        // Identifiers above are fixed native mappings; field names never become SQL.
        $table=match($type){'Contact'=>'contact','Account'=>'account','Opportunity'=>'opportunity'};
        $query=$this->em->getPDO()->prepare("SELECT id FROM $table WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0 FOR UPDATE");$query->execute([$id,...$this->scope()]);
        if(!$query->fetchColumn())throw new NotFound('The associated record is unavailable.');
        $entity=$this->records->createForUser($type,$actor)->getEntity($id);$this->owned($entity);
        if(!$this->aclManager->createUserAcl($actor)->check($entity,'edit'))throw new Forbidden('Record edit access is required.');
        $field=$settings['field'] ?? (str_starts_with($action,'score.')?'leadScore':'tags');$before=$entity->get($field);
        if(str_starts_with($action,'score.')) {
            $value=max(0,min(1000000,(int)$before+($action==='score.increase'?1:-1)*$settings['amount']));
            if($value!==(int)$before) {
                // Score is protected from generic form updates; only this bounded adapter writes it.
                $entity->set('leadScore',$value);$this->em->saveEntity($entity,[SaveOption::MODIFIED_BY_ID=>$actor->getId()]);
            }
        } else {
            $value=$settings['value'] ?? null;
            if(str_starts_with($action,'tags.')) {
                $tags=is_array($before)?$before:[];
                $value=$action==='tags.add'?array_values(array_unique([...$tags,$settings['tag']])):array_values(array_filter($tags,fn($tag)=>$tag!==$settings['tag']));
            }
            if($value!==$before) {
                $updated=$this->records->createForUser($type,$actor)->update($id,(object)[$field=>$value],UpdateParams::create());
                if($updated->get($field)!==$value)throw new BadRequest('The native property update was not applied.');
            }
        }
        return ['action'=>$action,'recordType'=>$type,'recordId'=>$id,'field'=>$field,'changed'=>$before!==$value];
    }

    private function scope(): array {$context=$this->contexts->require();return [$context->tenantId,$context->serviceId];}
    private function owned(Entity $entity): void
    {
        [$tenant,$service]=$this->scope();
        if($entity->get('deleted') || $entity->get('tenantId')!==$tenant || $entity->get('serviceId')!==$service)throw new Forbidden('Choose a record in this workspace.');
    }
}
