<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\User;

/** A reviewed Page-visitor opt-in permits service replies only, never CRM consent or marketing. */
final class MessengerOptInReview
{
    public function __construct(private ChatStore $store,private ProviderSecretCipher $cipher) {}
    public function state(string $conversation,bool $administrator=false): array
    {
        $rows=$this->store->rows('SELECT id,is_opt_out,provider_at,payload_encrypted FROM nexa_messenger_event WHERE tenant_id=? AND service_id=? AND conversation_id=? AND (is_opt_out=1 OR is_opt_in=1) ORDER BY provider_at,id LIMIT 1001',[...$this->store->scope(),$conversation]);$stops=[];$lastStop=null;$start=null;$fresh=false;
        if(count($rows)>1000)return ['blocked'=>true,'historicalOptOut'=>true,'released'=>false,'snapshot'=>null,'startEventId'=>null,'canReview'=>false];
        foreach($rows as $row){
            if($row['is_opt_out']){$stops[]=$row['id'];$lastStop=$row['provider_at'];$start=null;continue;}
            if(!$lastStop||$row['provider_at']<=$lastStop)continue;
            $p=json_decode($this->cipher->decryptFor($row['payload_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messenger-event:'.$row['id']]),true,16,JSON_THROW_ON_ERROR);
            if(in_array(strtoupper(trim($p['body'])),['START','UNSTOP','SUBSCRIBE'],true)){$start=$row['id'];$fresh=$p['timestamp']>=(time()-86400)*1000;}
        }
        sort($stops);$fingerprint=hash('sha256',json_encode($stops,JSON_THROW_ON_ERROR));$released=false;
        if($stops&&$start)$released=(bool)$this->store->rows("SELECT id FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND subject_type='NexaMessagingConversation' AND subject_id=? AND action='messenger.opt_out_released' AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json,'$.stopFingerprint'))=? LIMIT 1",[...$this->store->scope(),$conversation,$fingerprint]);
        return ['blocked'=>(bool)$stops&&!$released,'historicalOptOut'=>(bool)$stops,'released'=>$released,'snapshot'=>$fingerprint,'startEventId'=>$start,'canReview'=>$administrator&&(bool)$stops&&!$released&&$start!==null&&$fresh];
    }
    public function release(string $conversation,\stdClass $input,User $user): array
    {
        if(array_diff(array_keys((array)$input),['op','id','snapshot','startEventId','reason','evidence','policyVersion']))throw new BadRequest('Unknown review property.');
        foreach(['reason','evidence'] as $key)if(!is_string($input->$key??null)||mb_strlen(trim($input->$key))<10||mb_strlen($input->$key)>1000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$input->$key))throw new BadRequest('Record a review reason and renewed-consent evidence of 10 to 1,000 characters.');
        if(!is_string($input->policyVersion??null)||!preg_match('/^[A-Za-z0-9._-]{1,40}$/D',$input->policyVersion)||!is_string($input->snapshot??null)||!preg_match('/^[a-f0-9]{64}$/D',$input->snapshot))throw new BadRequest('Enter the policy version and current evidence snapshot.');ChatStore::uuidValue($input->startEventId??null);
        if(!$this->store->rows("SELECT id FROM user WHERE tenant_id=? AND service_id=? AND id=? AND type='admin' AND is_active=1 AND deleted=0",[...$this->store->scope(),$user->getId()]))throw new Forbidden('An active tenant administrator must review opt-in.');
        $state=$this->state($conversation,$user->isAdmin());if($state['snapshot']!==$input->snapshot||$state['startEventId']!==$input->startEventId)throw new Conflict('Preference evidence changed. Refresh the review.');
        if($state['released'])return ['released'=>true,'duplicate'=>true];if(!$state['canReview'])throw new Forbidden('A signed renewed opt-in after the latest opt-out is required.');
        $metadata=['stopFingerprint'=>$state['snapshot'],'startEventId'=>$state['startEventId'],'reason'=>trim($input->reason),'evidence'=>trim($input->evidence),'policyVersion'=>$input->policyVersion,'purpose'=>'customer_service_only','contactConsentChanged'=>false,'doNotContactChanged'=>false];
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,'messenger.opt_out_released','NexaMessagingConversation',?,?,'messenger-opt-in-review',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$user->getId(),$conversation,ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
        return ['released'=>true,'duplicate'=>false];
    }
}
