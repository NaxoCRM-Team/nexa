<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest, Conflict, Forbidden};
use Espo\Core\ORM\EntityManager;
use Espo\Custom\Tools\Contact\CommunicationRestrictionGuard;
use Espo\ORM\Entity;
use stdClass;

/** Caller owns the session authorization and service-before-conversation lock order. */
final class ChatBotRuntime
{
    public function __construct(private EntityManager $em, private ChatStore $store, private CommunicationRestrictionGuard $restrictions) {}

    public function initialize(Entity $root, array $widget): void
    {
        if (!(bool)($widget['bot_enabled'] ?? false) || !$widget['bot_definition_json']) return;
        $root=$this->store->lock($root->getId());
        if ($root->get('botSnapshot') || $root->get('assignedUserId') || $root->get('handoffRequestedAt')) return;
        $definition=(new ChatBotDefinition())->validate(json_decode($widget['bot_definition_json'],true,512,JSON_THROW_ON_ERROR))['definition'];
        $root->set(['botSnapshot'=>json_encode($definition,JSON_THROW_ON_ERROR),'botReceipts'=>'{}']);
        $this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
        $this->apply($root,(new ChatBotTransition())->advance($definition));
    }
    public function choose(Entity $root, stdClass $input): void
    {
        $root=$this->store->lock($root->getId());
        $nonce=ChatStore::uuidValue($input->nonce??null);
        if (!is_int($input->choice??null) || !is_string($input->step??null)) throw new BadRequest('Choose an available chat option.');
        $receipts=json_decode($root->get('botReceipts')?:'{}',true,512,JSON_THROW_ON_ERROR);
        $intent=['step'=>$input->step,'choice'=>$input->choice];
        if (isset($receipts[$nonce])) {
            if ($receipts[$nonce]!==$intent) throw new Conflict('This choice key was already used.');
            return;
        }
        if ($root->get('botState')!=='waiting' || $root->get('botStep')!==$input->step || $root->get('assignedUserId') || $root->get('handoffRequestedAt')) throw new Conflict('This chat question is no longer active.');
        $proof=$this->store->rows('SELECT p.contact_id FROM nexa_personalization_identity_proof p INNER JOIN nexa_visitor_identity v ON v.id=p.visitor_identity_id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.contact_id=p.contact_id INNER JOIN user u ON u.id=p.issuer_user_id AND u.tenant_id=p.tenant_id AND u.service_id=p.service_id AND u.deleted=0 AND u.is_active=1 WHERE p.tenant_id=? AND p.service_id=? AND p.visitor_identity_id=? AND p.issuer_user_id=? AND v.visitor_key_hash=? AND p.revoked_at IS NULL AND p.expires_at>UTC_TIMESTAMP(6)',[...$this->store->scope(),$root->get('visitorIdentityId'),$root->get('identityIssuerId'),$root->get('visitorKeyHash')])[0]??null;
        if ($proof) {
            try {$this->restrictions->assertContactAllowed($proof['contact_id'],'live_chat');}
            catch (Forbidden $error) {throw new BadRequest('Automated replies are restricted for this contact.');}
        }
        if (count($receipts)>=50) throw new BadRequest('Chat choice limit reached.');
        $definition=json_decode($root->get('botSnapshot'),true,512,JSON_THROW_ON_ERROR);
        $result=(new ChatBotTransition())->advance($definition,$root->get('botStep'),$input->choice);
        foreach ($definition['nodes'] as $node) if ($node['id']===$root->get('botStep')) {
            $this->store->append($root,$nonce,$node['options'][$input->choice]['label']);
        }
        $root=$this->store->lock($root->getId());
        $receipts[$nonce]=$intent;
        $root->set('botReceipts',json_encode($receipts,JSON_THROW_ON_ERROR));
        $this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
        $this->apply($root,$result);
    }
    public function state(Entity $root): array
    {
        $state=$root->get('botState')?:'none'; $choices=[];
        if ($state==='waiting') {
            $definition=json_decode($root->get('botSnapshot'),true,512,JSON_THROW_ON_ERROR);
            foreach ($definition['nodes'] as $node) if ($node['id']===$root->get('botStep')) $choices=array_column($node['options'],'label');
        }
        return ['state'=>$state,'step'=>$state==='waiting'?$root->get('botStep'):null,'choices'=>$choices,'revision'=>(int)$root->get('versionNumber')];
    }
    public function stop(Entity $root, string $state = 'staff'): void
    {
        $root=$this->store->lock($root->getId());
        if (in_array($root->get('botState'),['waiting','running'],true)) {
            $root->set('botState',$state); $this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
        }
    }
    private function apply(Entity $root, array $result): void
    {
        foreach ($result['messages'] as $message) $this->store->append($root,ChatStore::uuid(),$message['body'],null,true);
        $root=$this->store->lock($root->getId());
        $root->set(['botState'=>$result['state'],'botStep'=>$result['step']]);
        $this->em->saveEntity($root,['silent'=>true,'skipModifiedBy'=>true]);
        if ($result['state']==='handoff') $this->store->handoff($root);
    }
}
