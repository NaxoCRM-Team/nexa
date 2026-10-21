<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Entities\User;
use stdClass;

/** Releases provider suppression only; never writes consent, DNC or phone opt-out. */
final class SmsOptInReview
{
    public function __construct(private ChatStore $store,private User $user,private SmsOutboxService $outbox,private SmsPreferenceService $preferences) {}
    public function view(string $id): array
    {
        $root=$this->outbox->root($id);
        if(!$this->user->isAdmin())return ['canReview'=>false];
        $state=$this->preferences->state($root->get('connectionId'),$root->get('peerHash'));
        $snapshot=$this->preferences->reviewSnapshot($root->get('connectionId'),$root->get('peerHash'));
        $purposes=[];
        if($state['blocked']&&!$state['pending']&&$snapshot['startId']&&$root->get('contactId')){
            $rows=$this->store->rows("SELECT p.id,p.name,e.id AS event_id,e.occurred_at FROM nexa_consent_state s JOIN nexa_consent_purpose p ON p.id=s.purpose_id AND p.tenant_id=s.tenant_id AND p.service_id=s.service_id JOIN nexa_consent_event e ON e.id=s.source_event_id AND e.tenant_id=s.tenant_id AND e.service_id=s.service_id WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? AND s.channel='sms' AND s.status='granted' AND s.legal_basis='FreelyGivenConsent' AND e.occurred_at>? ORDER BY p.position,p.name LIMIT 100",[...$this->store->scope(),$root->get('contactId'),$snapshot['startAt']]);
            foreach($rows as $row){try{$check=$this->outbox->check($id,$row['id'],true);if($check['purpose']['source_event_id']!==$row['event_id'])continue;$released=$this->preferences->released($root->get('connectionId'),$root->get('peerHash'),$root->get('contactId'),$row['id'],$row['event_id']);$purposes[]=['id'=>$row['id'],'name'=>$row['name'],'consentEventId'=>$row['event_id'],'released'=>$released];}catch(Forbidden|NotFound $error){}}
        }
        return ['canReview'=>$state['blocked'],'pending'=>$state['pending'],'snapshot'=>$snapshot['fingerprint'],'startReceiptId'=>$snapshot['startId'],'purposes'=>$purposes];
    }
    public function release(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','purposeId','consentEventId','snapshot','startReceiptId','reason'])||!is_string($input->id??null)||!is_string($input->reason??null)||mb_strlen(trim($input->reason))<10||mb_strlen($input->reason)>1000)throw new BadRequest('Add a review reason of 10 to 1,000 characters.');
        foreach(['purposeId','consentEventId','startReceiptId'] as $key)ChatStore::uuidValue($input->$key??null);
        if(!is_string($input->snapshot??null)||!preg_match('/^[a-f0-9]{64}$/D',$input->snapshot))throw new BadRequest('Refresh the opt-in review.');
        $this->outbox->root($input->id,'edit');if(!$this->user->isAdmin())throw new Forbidden('A tenant administrator must review SMS opt-in.');
        return $this->store->atomic(function()use($input):array{
            $locked=$this->store->rows('SELECT contact_id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND id=? AND deleted=0 FOR UPDATE',[...$this->store->scope(),$input->id])[0]??null;
            if(!$locked||!$locked['contact_id'])throw new NotFound('Linked Contact unavailable.');
            $this->store->rows("SELECT s.source_event_id FROM nexa_consent_state s JOIN nexa_consent_purpose p ON p.tenant_id=s.tenant_id AND p.service_id=s.service_id AND p.id=s.purpose_id JOIN nexa_consent_event e ON e.tenant_id=s.tenant_id AND e.service_id=s.service_id AND e.id=s.source_event_id WHERE s.tenant_id=? AND s.service_id=? AND s.contact_id=? AND s.purpose_id=? AND s.channel='sms' FOR UPDATE",[...$this->store->scope(),$locked['contact_id'],$input->purposeId]);
            $this->store->rows('SELECT id FROM contact WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$locked['contact_id']]);
            $root=$this->outbox->root($input->id,'edit');$review=$this->view($input->id);
            if(($review['pending']??true)||($review['snapshot']??null)!==$input->snapshot||($review['startReceiptId']??null)!==$input->startReceiptId)throw new Conflict('SMS preference evidence changed. Refresh the review.');
            $purpose=null;foreach($review['purposes']??[] as $item)if($item['id']===$input->purposeId&&$item['consentEventId']===$input->consentEventId)$purpose=$item;
            if(!$purpose)throw new Forbidden('A fresh explicit SMS consent grant after the signed START is required. Check do-not-contact and Contact permissions.');
            if($purpose['released'])return ['released'=>true,'duplicate'=>true];
            $metadata=['peerHash'=>$root->get('peerHash'),'contactId'=>$root->get('contactId'),'purposeId'=>$input->purposeId,'consentEventId'=>$input->consentEventId,'stopFingerprint'=>$input->snapshot,'startReceiptId'=>$input->startReceiptId,'reason'=>trim($input->reason)];
            $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,'sms.suppression_released','NexaMessagingConnection',?,?,'sms-opt-in-review',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$root->get('connectionId'),ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
            // Old blocked intents are never revived by a suppression review.
            return ['released'=>true,'duplicate'=>false];
        },true);
    }
}
