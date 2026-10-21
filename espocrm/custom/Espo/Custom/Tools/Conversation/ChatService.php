<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\{Acl, InjectableFactory};
use Espo\Core\Exceptions\{BadRequest, Conflict, Forbidden, NotFound};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Event\PublicEventCollectorService;
use Espo\Custom\Tools\Customer\CustomerFoundationRecorder;
use Espo\Entities\User;
use Espo\ORM\Entity;
use stdClass;

/** Staff adapter delegates record/field access to native CRM roles. */
final class ChatService
{
    public function __construct(private EntityManager $em, private TenantContextStore $contexts, private ChatStore $store, private User $user, private Acl $acl, private ServiceFactory $records, private InjectableFactory $factory, private Config $config) {}
    public function process(stdClass $input): array
    {
        $this->access();
        if (strlen(json_encode($input,JSON_THROW_ON_ERROR))>20000) throw new BadRequest('The chat request is too large.');
        return match ($input->op ?? '') {
            'widgets' => $this->widgets(), 'widgetSave' => $this->saveWidget($input),
            'botSave' => $this->saveBot($input),
            'list' => $this->listing($input), 'thread' => $this->thread((string) ($input->id ?? ''), $input), 'owners' => $this->owners($input),
            'send' => $this->send($input), 'update' => $this->update($input), 'identify' => $this->identify($input),
            default => throw new BadRequest('Choose a chat operation.'),
        };
    }
    private function access(): void
    {
        if (!$this->user->isActive() || !in_array($this->user->getType(), ['admin','regular','api'], true) || [$this->user->get('tenantId'),$this->user->get('serviceId')] !== $this->store->scope() || !$this->acl->checkScope('NexaChatConversation','read')) throw new Forbidden('Live Chat permission is required.');
        if(!$this->store->rows("SELECT u.id FROM user u INNER JOIN nexa_tenant t ON t.id=u.tenant_id AND t.status='active' INNER JOIN nexa_tenant_service ts ON ts.tenant_id=u.tenant_id AND ts.service_id=u.service_id AND ts.status='active' INNER JOIN nexa_service_definition d ON d.id=u.service_id AND d.status='active' WHERE u.tenant_id=? AND u.service_id=? AND u.id=? AND u.type=? AND u.is_active=1 AND u.deleted=0",[...$this->store->scope(),$this->user->getId(),$this->user->getType()]))throw new Forbidden('An active staff member and workspace are required.');
        foreach (['name','status','assignedUser'] as $field) if (!$this->acl->checkField('NexaChatConversation',$field,'read')) throw new Forbidden('Chat property access is required.');
    }
    private function root(string $id, string $action = 'read'): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9]{1,17}$/D',$id)) throw new NotFound('Chat unavailable.');
        $root = $this->records->createForUser('NexaChatConversation',$this->user)->getEntity($id);
        if (!$root || [$root->get('tenantId'),$root->get('serviceId')] !== $this->store->scope() || !$this->acl->checkEntity($root,$action)) throw new NotFound('Chat unavailable.');
        return $root;
    }
    private function summary(Entity $root): array
    {
        $contact=$this->contact($root);
        return ['id'=>$root->getId(),'name'=>$contact['name']??$root->get('name'),'status'=>$root->get('status'),'assignedUserId'=>$root->get('assignedUserId'),'version'=>(int)$root->get('versionNumber'),'createdAt'=>$root->get('createdAt'),'handoff'=>$this->store->handoffState($root)];
    }
    private function listing(stdClass $input): array
    {
        $before = $input->before ?? ''; $q = $input->q ?? ''; $status = $input->status ?? '';
        if (!is_string($before) || ($before !== '' && !preg_match('/^[a-zA-Z0-9]{1,17}$/D',$before)) || !is_string($q) || mb_strlen($q)>160 || !in_array($status,['','Open','Pending','Closed'],true)) throw new BadRequest('Choose valid chat filters.');
        $rows = $this->store->rows('SELECT id FROM nexa_chat_conversation WHERE tenant_id=? AND service_id=? AND deleted=0 AND id<? ORDER BY id DESC LIMIT 100',[...$this->store->scope(),$before ?: str_repeat('z',17)]);
        $list=[];
        foreach ($rows as $row) {
            try { $root=$this->root($row['id']); } catch (Forbidden|NotFound $error) { continue; }
            $item=$this->summary($root);
            if (($status !== '' && $root->get('status') !== $status) || ($q !== '' && mb_stripos((string)$item['name'],$q)===false)) continue;
            $list[]=$item;
        }
        return ['list'=>$list,'nextCursor'=>count($rows)===100 ? end($rows)['id'] : null,'canConfigure'=>$this->user->isAdmin()];
    }
    private function thread(string $id, stdClass $input): array
    {
        $root=$this->root($id);
        if (!$this->acl->checkField('NexaChatConversation','messages','read')) throw new Forbidden('Chat message access is required.');
        $owner=null;
        $blocked=false; try { $this->communicationAllowed($root); } catch(Forbidden $error) { $blocked=true; }
        $active=$root->get('expiresAt')>gmdate('Y-m-d H:i:s');
        if ($root->get('assignedUserId')) { try { $owner=$this->staff($root->get('assignedUserId'))->get('name'); } catch (Forbidden|NotFound $error) {} }
        $history=$this->store->history($id,$input->after??'',$input->before??''); $authors=[];
        if ($history['messages']) {
            $messages=$history['messages'];
            foreach ($this->store->rows("SELECT id,author_user_id FROM nexa_chat_message WHERE tenant_id=? AND service_id=? AND conversation_id=? AND id>=? AND id<=? AND author_kind='staff' ORDER BY id LIMIT 100",[...$this->store->scope(),$id,$messages[0]['id'],end($messages)['id']]) as $row) $authors[(string)$row['id']]=$row['author_user_id'];
        }
        $names=[];
        foreach ($history['messages'] as &$message) if (isset($authors[$message['id']])) {
            $u=$authors[$message['id']];
            if (!array_key_exists($u,$names)) {try{$names[$u]=$this->staff($u)->get('name');}catch(Forbidden|NotFound $error){$names[$u]='Support';}}
            $message['authorLabel']=$names[$u];
        }
        unset($message);
        return ['conversation'=>$this->summary($root)+['ownerName'=>$owner], 'contact'=>$this->contact($root),
            'canReply'=>$active && !$blocked && $this->acl->checkEntity($root,'edit') && $this->acl->checkField('NexaChatConversation','messages','edit'), 'communicationBlocked'=>$blocked, 'sessionActive'=>$active,
            'canDelete'=>$this->acl->checkEntity($root,'delete') && $this->acl->checkField('NexaChatConversation','messages','read'),
            'canAssign'=>$this->acl->checkEntity($root,'edit') && $this->acl->checkField('NexaChatConversation','assignedUser','edit') && $this->acl->checkField('NexaChatConversation','status','edit') && $this->acl->getPermissionLevel('assignment') === 'all']
            +$history;
    }
    private function send(stdClass $input): array
    {
        $id=(string)($input->id??''); $this->root($id,'edit');
        if (!$this->acl->checkField('NexaChatConversation','messages','edit') || !$this->acl->checkField('NexaChatConversation','messages','read')) throw new Forbidden('Chat reply permission is required.');
        return $this->store->atomic(function () use ($id,$input): array {
            $root=$this->store->lock($id); $this->root($id,'edit');
            $this->communicationAllowed($root);
            if ($root->get('expiresAt')<=gmdate('Y-m-d H:i:s')) throw new BadRequest('The visitor chat session has ended.');
            $this->factory->create(ChatBotRuntime::class)->stop($root);
            $result=$this->store->append($root,$input->nonce??null,$input->body??null,$this->user->getId());
            if (!$result['duplicate']) {
                $this->store->answerHandoff($root,$this->user->getId());
                $this->audit($id,'replied',['messageId'=>$result['id']]);
            }
            return $result;
        },true);
    }
    private function update(stdClass $input): array
    {
        $id=(string)($input->id??''); $this->root($id,'edit');
        if (!is_int($input->version??null) || !in_array($input->status??null,['Open','Pending','Closed'],true) || !property_exists($input,'ownerId') || !($input->ownerId === null || is_string($input->ownerId))) throw new BadRequest('Provide the current chat state.');
        if (!$this->acl->checkField('NexaChatConversation','assignedUser','edit') || !$this->acl->checkField('NexaChatConversation','status','edit') || $this->acl->getPermissionLevel('assignment') !== 'all') throw new Forbidden('Chat assignment permission is required.');
        return $this->store->atomic(function () use ($id,$input): array {
            $root=$this->store->lock($id); $this->root($id,'edit');
            if ((int)$root->get('versionNumber') !== $input->version) throw new Conflict('The chat changed. Refresh before updating it.');
            if ($input->ownerId !== null) $this->staff($input->ownerId);
            $before=['status'=>$root->get('status'),'ownerId'=>$root->get('assignedUserId')];
            $root->set(['status'=>$input->status,'assignedUserId'=>$input->ownerId]); $this->em->saveEntity($root);
            if ($input->ownerId!==null || $input->status==='Closed') $this->factory->create(ChatBotRuntime::class)->stop($root);
            $root=$this->store->lock($id);
            $this->audit($id,'updated',['previous'=>$before,'status'=>$input->status,'ownerId'=>$input->ownerId]);
            return $this->summary($root);
        });
    }
    private function staff(string $id): User
    {
        $u=$this->records->createForUser('User',$this->user)->getEntity($id);
        if (!$u instanceof User || !$u->isActive() || !in_array($u->getType(),['admin','regular'],true) || [$u->get('tenantId'),$u->get('serviceId')]!==$this->store->scope() || !$this->acl->checkEntity($u,'read')) throw new NotFound('Staff member unavailable.');
        foreach (['name','firstName','lastName'] as $field) if (!$this->acl->checkField('User',$field,'read')) throw new NotFound('Staff member unavailable.');
        return $u;
    }
    private function owners(stdClass $input): array
    {
        $q=$input->q??''; if (!is_string($q)||mb_strlen($q)>100) throw new BadRequest('Enter a shorter staff search.');
        foreach (['name','firstName','lastName'] as $field) if (!$this->acl->checkField('User',$field,'read')) return ['list'=>[]];
        $pattern='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$q).'%';
        $list=[];
        foreach ($this->store->rows("SELECT id FROM user WHERE tenant_id=? AND service_id=? AND deleted=0 AND is_active=1 AND type IN ('admin','regular') AND CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) LIKE ? ORDER BY id LIMIT 100",[...$this->store->scope(),$pattern]) as $row) {
            try { $u=$this->staff($row['id']); } catch(Forbidden|NotFound $error) { continue; }
            if ($q===''||mb_stripos((string)$u->get('name'),$q)!==false) $list[]=['id'=>$u->getId(),'name'=>$u->get('name')];
        }
        return ['list'=>$list];
    }
    private function widgets(): array
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Tenant administrator access is required.');
        $rows=$this->store->rows('SELECT * FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? ORDER BY created_at,id LIMIT 10',$this->store->scope());
        foreach ($rows as &$row) {
            $row['origins']=json_decode($row['allowed_origins_json'],true); unset($row['allowed_origins_json'],$row['tenant_id'],$row['service_id']);
            $base=rtrim((string)$this->config->get('siteUrl'),'/');
            $row['embed']='<script src="'.htmlspecialchars($base.'/client/custom/nexa-chat-widget.js',ENT_QUOTES).'" data-nexa-chat-api="'.htmlspecialchars($base.'/api/v1/Nexa/public/chat/'.$row['public_key'],ENT_QUOTES).'" defer></script>';
        }
        return ['list'=>$rows];
    }
    private function saveWidget(stdClass $input): array
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Tenant administrator access is required.');
        $name=$input->name??null; $title=$input->title??null; $welcome=$input->welcome??null; $origins=$input->origins??null;
        if (!is_string($name)||trim($name)===''||mb_strlen($name)>120||!is_string($title)||trim($title)===''||mb_strlen($title)>120||!is_string($welcome)||mb_strlen($welcome)>500||!is_array($origins)||count($origins)<1||count($origins)>10) throw new BadRequest('Enter a name, title, welcome message and up to ten approved website origins.');
        $origins=array_values(array_unique(array_map(fn($origin)=>PublicEventCollectorService::normalizeOrigin(is_string($origin)?$origin:''),$origins)));
        if (!is_string($input->color??null)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$input->color)||!in_array($input->position??null,['left','right'],true)||!is_bool($input->draggable??null)||!is_bool($input->enabled??null)||!is_string($input->privacyUrl??null)||strlen($input->privacyUrl)>1000) throw new BadRequest('Choose valid widget appearance settings.');
        if ($input->privacyUrl!=='') PublicEventCollectorService::normalizeOrigin($input->privacyUrl);
        return $this->store->atomic(function () use($input,$name,$title,$welcome,$origins):array {
            $this->store->rows('SELECT tenant_id FROM nexa_tenant_service WHERE tenant_id=? AND service_id=? FOR UPDATE',$this->store->scope());
            $id=$input->id??null;
            if ($id!==null) {
                ChatStore::uuidValue($id);
                $old=$this->store->rows('SELECT version_number FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;
                if (!$old) throw new NotFound('Widget unavailable.');
                if (!is_int($input->version??null)||(int)$old['version_number']!==$input->version) throw new Conflict('The widget changed. Refresh before saving.');
                $this->store->execute('UPDATE nexa_chat_widget SET name=?,title=?,welcome=?,allowed_origins_json=?,color=?,position=?,draggable=?,privacy_url=?,is_enabled=?,version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?',[$name,$title,$welcome,json_encode($origins),$input->color,$input->position,(int)$input->draggable,$input->privacyUrl,(int)$input->enabled,...$this->store->scope(),$id]);
            } else {
                if (count($this->store->rows('SELECT id FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? LIMIT 10',$this->store->scope()))>=10) throw new BadRequest('The workspace supports up to ten chat widgets.');
                $id=ChatStore::uuid();
                $this->store->execute('INSERT INTO nexa_chat_widget (id,tenant_id,service_id,public_key,name,title,welcome,allowed_origins_json,color,position,draggable,privacy_url,is_enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,...$this->store->scope(),bin2hex(random_bytes(24)),$name,$title,$welcome,json_encode($origins),$input->color,$input->position,(int)$input->draggable,$input->privacyUrl,(int)$input->enabled]);
            }
            $this->audit($id,'widget_saved',['enabled'=>$input->enabled]); return ['id'=>$id];
        });
    }
    private function saveBot(stdClass $input): array
    {
        if (!$this->user->isAdmin()) throw new Forbidden('Tenant administrator access is required.');
        $id=ChatStore::uuidValue($input->id??null);
        if (!is_bool($input->enabled??null) || !is_int($input->version??null)) throw new BadRequest('Choose valid bot settings.');
        $definition=null;
        if ($input->enabled || isset($input->definition)) {
            $data=json_decode(json_encode($input->definition??null,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new BadRequest('Choose a chat flow.');
            $definition=(new ChatBotDefinition())->validate($data);
        }
        return $this->store->atomic(function () use($input,$id,$definition):array {
            $old=$this->store->rows('SELECT version_number FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$id])[0]??null;
            if (!$old) throw new NotFound('Widget unavailable.');
            if ((int)$old['version_number']!==$input->version) throw new Conflict('The widget changed. Refresh before saving.');
            $this->store->execute('UPDATE nexa_chat_widget SET bot_enabled=?,bot_definition_json=?,version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?',[(int)$input->enabled,$definition?json_encode($definition['definition'],JSON_THROW_ON_ERROR):null,...$this->store->scope(),$id]);
            $this->audit($id,'widget_saved',['botEnabled'=>$input->enabled,'botFingerprint'=>$definition['fingerprint']??null]);
            return ['id'=>$id,'version'=>$input->version+1];
        });
    }
    private function identify(stdClass $input): array
    {
        if (!in_array($this->user->getType(),['admin','api'],true)) throw new Forbidden('An authorized website backend is required.');
        $id=(string)($input->id??''); ChatStore::uuidValue($input->visitorKey??null); $this->root($id,'edit');
        return $this->store->atomic(function () use($input,$id):array {
            $root=$this->store->lock($id); $c=$this->contexts->require();
            $hash=hash('sha256',$c->tenantId."\0".$c->serviceId."\0".$input->visitorKey);
            if (!hash_equals((string)$root->get('visitorKeyHash'),$hash)) throw new NotFound('Verified visitor unavailable.');
            $proof=$this->store->rows('SELECT p.visitor_identity_id FROM nexa_personalization_identity_proof p INNER JOIN nexa_visitor_identity v ON v.id=p.visitor_identity_id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.contact_id=p.contact_id WHERE p.tenant_id=? AND p.service_id=? AND p.issuer_user_id=? AND v.visitor_key_hash=? AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6)',[...$this->store->scope(),$this->user->getId(),$hash])[0]??null;
            if (!$proof) throw new NotFound('An unexpired backend-verified visitor proof is required.');
            $root->set(['visitorIdentityId'=>$proof['visitor_identity_id'],'identityIssuerId'=>$this->user->getId()]);
            if (!$this->contact($root)) throw new NotFound('Verified contact unavailable.');
            $this->em->saveEntity($root); $this->audit($id,'identified'); return ['verified'=>true];
        });
    }
    private function contact(Entity $root): ?array
    {
        if (!$root->get('visitorIdentityId') || !$this->acl->checkScope('Contact','read')) return null;
        foreach (['firstName','lastName','name'] as $field) if (!$this->acl->checkField('Contact',$field,'read')) return null;
        $proof=$this->proof($root);
        if (!$proof) return null;
        try { $contact=$this->records->createForUser('Contact',$this->user)->getEntity($proof['contact_id']); } catch(Forbidden|NotFound $error) { return null; }
        if (!$contact || [$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()) return null;
        return ['id'=>$contact->getId(),'name'=>$contact->get('name')];
    }
    public function contactTimeline(string $contactId,int $limit=50): array
    {
        if($this->user->isSystem()||!$this->acl->checkScope('NexaChatConversation','read')||!$this->acl->checkScope('Contact','read')||!$this->acl->checkField('NexaChatConversation','messages','read'))return [];
        foreach(['name','firstName','lastName'] as $field)if(!$this->acl->checkField('Contact',$field,'read'))return [];
        $this->access();
        $contact=$this->records->createForUser('Contact',$this->user)->getEntity($contactId);
        if(!$contact||[$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope()||!$this->acl->checkEntity($contact,'read'))throw new NotFound('Contact unavailable.');
        $limit=min(200,max(1,$limit));
        $rows=$this->store->rows("SELECT m.id,m.conversation_id,m.author_kind,m.author_user_id,m.body,m.created_at FROM nexa_chat_conversation c INNER JOIN nexa_visitor_identity v ON v.id=c.visitor_identity_id AND v.tenant_id=c.tenant_id AND v.service_id=c.service_id AND v.visitor_key_hash=c.visitor_key_hash INNER JOIN nexa_personalization_identity_proof p ON p.visitor_identity_id=v.id AND p.tenant_id=c.tenant_id AND p.service_id=c.service_id AND p.contact_id=v.contact_id AND p.issuer_user_id=c.identity_issuer_id INNER JOIN user u ON u.id=p.issuer_user_id AND u.tenant_id=c.tenant_id AND u.service_id=c.service_id AND u.is_active=1 AND u.deleted=0 INNER JOIN nexa_chat_message m ON m.conversation_id=c.id AND m.tenant_id=c.tenant_id AND m.service_id=c.service_id WHERE c.tenant_id=? AND c.service_id=? AND v.contact_id=? AND c.deleted=0 AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6) ORDER BY m.created_at DESC,m.id DESC LIMIT 200",[...$this->store->scope(),$contactId]);
        $roots=[];$authors=[];$list=[];
        foreach($rows as $row){
            if(!array_key_exists($row['conversation_id'],$roots)){
                try{$root=$this->root($row['conversation_id']);$roots[$row['conversation_id']]=($this->contact($root)['id']??null)===$contactId;}
                catch(Forbidden|NotFound $error){$roots[$row['conversation_id']]=false;}
            }
            if(!$roots[$row['conversation_id']])continue;
            $author=$row['author_kind'];if(!in_array($author,['visitor','staff','bot'],true))continue;
            $name=null;
            if($author==='staff'&&$row['author_user_id']){
                $userId=$row['author_user_id'];
                if(!array_key_exists($userId,$authors)){
                    try{$authors[$userId]=$this->staff($userId)->get('name');}
                    catch(Forbidden|NotFound $error){$authors[$userId]=null;}
                }
                $name=$authors[$userId];
            }
            $list[]=['id'=>(string)$row['id'],'conversationId'=>$row['conversation_id'],'author'=>$author,'authorName'=>$name,'body'=>$row['body'],'createdAt'=>$row['created_at']];
            if(count($list)>=$limit)break;
        }
        return $list;
    }
    private function proof(Entity $root): ?array
    {
        if (!$root->get('visitorIdentityId')) return null;
        return $this->store->rows('SELECT p.contact_id FROM nexa_personalization_identity_proof p INNER JOIN nexa_visitor_identity v ON v.id=p.visitor_identity_id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.contact_id=p.contact_id INNER JOIN user u ON u.id=p.issuer_user_id AND u.tenant_id=p.tenant_id AND u.service_id=p.service_id AND u.deleted=0 AND u.is_active=1 WHERE p.tenant_id=? AND p.service_id=? AND p.visitor_identity_id=? AND p.issuer_user_id=? AND v.visitor_key_hash=? AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6)',[...$this->store->scope(),$root->get('visitorIdentityId'),$root->get('identityIssuerId'),$root->get('visitorKeyHash')])[0]??null;
    }
    private function communicationAllowed(Entity $root): void
    {
        $proof=$this->proof($root);
        if ($proof) $this->factory->create(\Espo\Custom\Tools\Contact\CommunicationRestrictionGuard::class)->assertContactAllowed($proof['contact_id'],'live_chat');
    }
    private function audit(string $id,string $operation,array $metadata=[]):void
    {
        $this->factory->createWith(CustomerFoundationRecorder::class,['user'=>$this->user])->chatRecorded($id,$operation,$metadata);
    }
}
