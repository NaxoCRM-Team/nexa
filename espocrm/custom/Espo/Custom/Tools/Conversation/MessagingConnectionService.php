<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Acl;
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Core\Record\ServiceFactory;
use Espo\Entities\User;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Custom\Tools\Contact\CommunicationRestrictionGuard;
use PDOException;
use stdClass;

/** Tenant connection management; provider verification is read-only, never delivery. */
final class MessagingConnectionService
{
    public function __construct(private ChatStore $store, private ProviderSecretCipher $cipher, private User $user,
        private Acl $acl, private ServiceFactory $records, private CommunicationRestrictionGuard $restrictions, private TwilioMessagingLookup $lookup,private SmsPreferenceService $preferences,
        private \Espo\Core\Utils\Config $config,private MessagingCallbackService $callbacks,private WhatsAppTemplateLookup $templates) {}
    private function access(): void
    {
        if (!$this->user->isActive() || !$this->user->isAdmin() || [$this->user->get('tenantId'),$this->user->get('serviceId')]!==$this->store->scope() || !$this->currentAdministrator()) throw new Forbidden('An active tenant administrator and service are required.');
    }
    private function currentAdministrator(bool $lock=false): bool
    {
        if ($lock) {
            // Do not lock the platform-wide service definition across unrelated tenants.
            $this->store->rows('SELECT id FROM user WHERE tenant_id=? AND service_id=? AND id=? FOR UPDATE',[...$this->store->scope(),$this->user->getId()]);
            $this->store->rows('SELECT id FROM nexa_tenant WHERE id=? FOR UPDATE',[$this->store->scope()[0]]);
        }
        return (bool)$this->store->rows("SELECT u.id FROM user u INNER JOIN nexa_tenant t ON t.id=u.tenant_id AND t.status='active' INNER JOIN nexa_tenant_service ts ON ts.tenant_id=u.tenant_id AND ts.service_id=u.service_id AND ts.status='active' INNER JOIN nexa_service_definition d ON d.id=u.service_id AND d.status='active' WHERE u.tenant_id=? AND u.service_id=? AND u.id=? AND u.deleted=0 AND u.is_active=1 AND u.type='admin'",[...$this->store->scope(),$this->user->getId()]);
    }
    public function process(stdClass $input): array
    {
        $this->access();
        if (strlen(json_encode($input,JSON_THROW_ON_ERROR))>8192) throw new BadRequest('Connection settings exceed their limit.');
        return match($input->op??'') {'list'=>$this->listing(),'save'=>$this->save($input),'verify'=>$this->verify($input),'inspectWhatsAppTemplate'=>$this->inspectWhatsAppTemplate($input),'activate'=>$this->activate($input),'receipts'=>$this->receipts($input),'receipt'=>$this->receipt($input),default=>throw new BadRequest('Choose a connection operation.')};
    }
    private function inspectWhatsAppTemplate(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','version','contentSid'])||!is_int($input->version??null)||!is_string($input->contentSid??null))throw new BadRequest('Provide the current WhatsApp connection and Content SID.');
        $id=ChatStore::uuidValue($input->id??null);$row=$this->connection($id);
        if($row['channel']!=='whatsapp'||$row['status']!=='verified')throw new Forbidden('Verify the WhatsApp sender first.');
        if((int)$row['version_number']!==$input->version)throw new Conflict('The connection changed.');
        $secret=$this->cipher->decryptFor($row['secret_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging:'.$id]);
        $template=$this->templates->inspect($row['account_sid'],$secret,$input->contentSid);
        return $this->store->atomic(function()use($id,$input,$template):array{
            $current=$this->connection($id,true);
            if(!$this->currentAdministrator(true))throw new Forbidden('An active tenant administrator is required.');
            if($current['status']!=='verified'||(int)$current['version_number']!==$input->version)throw new Conflict('The connection changed during template inspection.');
            $catalogue=json_decode($current['whatsapp_templates_json']??'[]',true,32,JSON_THROW_ON_ERROR);
            if(count($catalogue)>=50&&!isset($catalogue[$template['contentSid']]))throw new BadRequest('A connection supports up to 50 registered templates.');
            $catalogue[$template['contentSid']]=$template;
            $this->store->execute('UPDATE nexa_messaging_connection SET whatsapp_templates_json=? WHERE tenant_id=? AND service_id=? AND id=?',[json_encode($catalogue,JSON_THROW_ON_ERROR),...$this->store->scope(),$id]);
            $this->audit($id,'whatsapp.template_registered',['contentSid'=>$template['contentSid']]);
            return $template;
        },true);
    }
    private function receipts(stdClass $input): array
    {
        if (array_diff(array_keys((array)$input),['op','connectionId','kind','before'])) throw new BadRequest('Unknown receipt filter.');
        $kind=$input->kind??'inbound';$connection=$input->connectionId??'';$before=$input->before??'';
        if (!in_array($kind,['inbound','status'],true) || !is_string($connection) || !is_string($before)) throw new BadRequest('Choose valid receipt filters.');
        if ($connection!=='') ChatStore::uuidValue($connection);
        $args=[...$this->store->scope(),$kind];$filter='';
        if ($connection!=='') {$filter.=' AND r.connection_id=?';$args[]=$connection;}
        if ($before!=='') {
            if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\|([a-f0-9-]{36})$/D',$before,$parts)) throw new BadRequest('Choose a valid receipt cursor.');
            ChatStore::uuidValue($parts[2]);$date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$parts[1],new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d H:i:s')!==$parts[1]) throw new BadRequest('Choose a valid receipt cursor.');
            $filter.=' AND (r.received_at<? OR (r.received_at=? AND r.id<?))';array_push($args,$parts[1],$parts[1],$parts[2]);
        }
        $rows=$this->store->rows('SELECT r.*,c.name AS connection_name FROM nexa_messaging_receipt r INNER JOIN nexa_messaging_connection c ON c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.id=r.connection_id WHERE r.tenant_id=? AND r.service_id=? AND r.kind=?'.$filter.' ORDER BY r.received_at DESC,r.id DESC LIMIT 51',$args);
        $more=count($rows)>50;$rows=array_slice($rows,0,50);$last=$rows?end($rows):null;
        return ['list'=>array_map(fn($row)=>$this->receiptSummary($row),$rows),'nextCursor'=>$more?$last['received_at'].'|'.$last['id']:null];
    }
    private function receipt(stdClass $input): array
    {
        if (array_diff(array_keys((array)$input),['op','id'])) throw new BadRequest('Unknown receipt request.');
        $id=ChatStore::uuidValue($input->id??null);
        $row=$this->store->rows('SELECT r.*,c.name AS connection_name FROM nexa_messaging_receipt r INNER JOIN nexa_messaging_connection c ON c.tenant_id=r.tenant_id AND c.service_id=r.service_id AND c.id=r.connection_id WHERE r.tenant_id=? AND r.service_id=? AND r.id=?',[...$this->store->scope(),$id])[0]??null;
        if (!$row) throw new NotFound('Message unavailable.');
        return $this->receiptSummary($row,true);
    }
    private function receiptSummary(array $row,bool $detail=false): array
    {
        [$tenant,$service]=$this->store->scope();
        $result=['id'=>$row['id'],'connectionName'=>$row['connection_name'],'kind'=>$row['kind'],'channel'=>'sms',
            'receivedAt'=>$row['received_at'],'disposition'=>$row['disposition']];
        if($detail)$result+=['conversationId'=>$row['conversation_id']??null,'projectionError'=>$row['projection_error_code']??null];
        try {
            $payload=json_decode($this->cipher->decryptFor($row['payload_encrypted'],$tenant,$service,'messaging-receipt:'.$row['id']),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload)||!is_string($payload['channel']??null))throw new \RuntimeException('Invalid stored receipt.');
        }catch(\RuntimeException|\JsonException $error) {
            return $result+['from'=>'Unknown sender','to'=>'','body'=>'Content unavailable','mediaCount'=>0,'mediaTypes'=>[],'payloadUnavailable'=>true];
        }
        $result['channel']=$payload['channel'];
        if ($row['kind']==='inbound') {
            $result+=['from'=>$payload['from'],'to'=>$payload['to'],'body'=>$detail?$payload['body']:mb_substr($payload['body'],0,160),
                'mediaCount'=>count($payload['media'])];
            // Provider media endpoints require privileged credentials; never expose them to the browser.
            if ($detail) $result['mediaTypes']=array_column($payload['media'],'contentType');
        } else $result+=['deliveryStatus'=>$payload['status'],'errorCode'=>$payload['errorCode']];
        return $result;
    }
    private function listing(): array
    {
        return ['activationAvailable'=>$this->config->get('nexaSmsDispatchEnabled')===true,'whatsappActivationAvailable'=>$this->config->get('nexaWhatsAppDispatchEnabled')===true,'list'=>$this->store->rows("SELECT id,name,provider,channel,account_sid,sender,status,dispatch_enabled,version_number,created_at,modified_at,verified_at,provider_resource_sid FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND provider='twilio' ORDER BY created_at,id LIMIT 20",$this->store->scope())];
    }
    private function activate(stdClass $input): array
    {
        if(array_diff(array_keys((array)$input),['op','id','version','enabled'])||!is_int($input->version??null)||!is_bool($input->enabled??null))throw new BadRequest('Provide the current sending setting.');
        ChatStore::uuidValue($input->id??null);
        return $this->store->atomic(function()use($input):array{
            $row=$this->connection($input->id,true);if(!$this->currentAdministrator(true))throw new Forbidden('Active administrator required.');
            if((int)$row['version_number']!==$input->version)throw new Conflict('The connection changed.');
            if($input->enabled){
                $key=$row['channel']==='whatsapp'?'nexaWhatsAppDispatchEnabled':'nexaSmsDispatchEnabled';
                if(!in_array($row['channel'],['sms','whatsapp'],true)||$this->config->get($key)!==true||$row['status']!=='verified'||!$row['verified_at']||strtotime($row['verified_at'].' UTC')<time()-86400)throw new Forbidden('Deployment approval and a fresh verified channel sender are required.');
                $this->callbacks->callbackUrl($row['id'],'status');
            }
            $this->store->execute('UPDATE nexa_messaging_connection SET dispatch_enabled=?,version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[(int)$input->enabled,...$this->store->scope(),$row['id']]);
            $this->audit($row['id'],'messaging.connection_dispatch_changed',['enabled'=>$input->enabled]);return ['id'=>$row['id']];
        },true);
    }
    private function save(stdClass $input): array
    {
        if (array_diff(array_keys((array)$input),['op','id','version','name','accountSid','channel','sender','secret','disabled'])) throw new BadRequest('Unknown connection setting.');
        foreach (['name','accountSid','channel','sender'] as $field) if (!is_string($input->$field??null)) throw new BadRequest('Complete the connection settings.');
        if (trim($input->name)==='' || mb_strlen($input->name)>120 || preg_match('/[\x00-\x1f\x7f]/',$input->name) || !is_bool($input->disabled??null)) throw new BadRequest('Enter a valid connection name and status.');
        (new TwilioMessagingAdapter())->outbound($input->accountSid,$input->channel,$input->sender,$input->sender,'Validation only','https://example.invalid/status');
        $secret=$input->secret??null;
        if ($secret!==null && (!is_string($secret)||!preg_match('/^[0-9a-fA-F]{32}$/D',$secret))) throw new BadRequest('Enter the provider auth token, or leave it unchanged.');
        return $this->store->atomic(function()use($input,$secret):array {
            $id=$input->id??null; $old=null;
            if ($id!==null) {
                ChatStore::uuidValue($id);$old=$this->connection($id,true);
                if (!is_int($input->version??null)||(int)$old['version_number']!==$input->version) throw new Conflict('The connection changed. Refresh before saving.');
                if ([$old['account_sid'],$old['channel'],$old['sender']]!==[$input->accountSid,$input->channel,$input->sender]) throw new BadRequest('Create a separate connection to change the account, channel or sender.');
            } else {
                if (!$secret) throw new BadRequest('A provider auth token is required.');
                if (count($this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? LIMIT 20',$this->store->scope()))>=20) throw new BadRequest('The workspace supports up to twenty messaging connections.');
                $id=ChatStore::uuid();
            }
            [$tenant,$service]=$this->store->scope();
            $encrypted=$secret!==null?$this->cipher->encryptFor($secret,$tenant,$service,'messaging:'.$id):$old['secret_encrypted'];
            $status=$input->disabled?'disabled':($secret===null&&($old['status']??null)==='verified'?'verified':'unverified');
            try {
                if ($old) $this->store->execute('UPDATE nexa_messaging_connection SET name=?,secret_encrypted=?,status=?,verified_at=?,provider_resource_sid=?,dispatch_enabled=IF(?=\'verified\',dispatch_enabled,0),version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?',[$input->name,$encrypted,$status,$status==='verified'?$old['verified_at']:null,$status==='verified'?$old['provider_resource_sid']:null,$status,$tenant,$service,$id]);
                else $this->store->execute('INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,channel,account_sid,sender,secret_encrypted,status,created_by_id,created_at,modified_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',[$id,$tenant,$service,$input->name,$input->channel,$input->accountSid,$input->sender,$encrypted,$status,$this->user->getId()]);
            } catch(PDOException $error) {if(($error->errorInfo[1]??null)===1062)throw new Conflict('This messaging sender is unavailable.');throw $error;}
            $this->audit($id,'messaging.connection_saved',['status'=>$status,'secretChanged'=>$secret!==null]);
            return ['id'=>$id,'version'=>$old?(int)$old['version_number']+1:1,'status'=>$status];
        },true);
    }
    private function connection(string $id, bool $lock=false): array
    {
        $row=$this->store->rows("SELECT * FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND provider='twilio'".($lock?' FOR UPDATE':''),[...$this->store->scope(),$id])[0]??null;
        if (!$row) throw new NotFound('Messaging connection unavailable.');return $row;
    }
    private function verify(stdClass $input): array
    {
        if (array_diff(array_keys((array)$input),['op','id','version','senderSid']) || !is_int($input->version??null)) throw new BadRequest('Provide the current connection revision.');
        $id=ChatStore::uuidValue($input->id??null);$snapshot=$this->connection($id);
        if ((int)$snapshot['version_number']!==$input->version) throw new Conflict('The connection changed. Refresh before verifying.');
        if ($snapshot['status']==='disabled') throw new Forbidden('Messaging connection is disabled.');
        if ($snapshot['channel']==='whatsapp'&&(!is_string($input->senderSid??null)||!preg_match('/^XE[0-9a-fA-F]{32}$/D',$input->senderSid)))throw new BadRequest('Enter the WhatsApp XE sender SID.');
        if ($snapshot['channel']==='sms'&&property_exists($input,'senderSid'))throw new BadRequest('SMS verification does not accept a WhatsApp sender SID.');
        [$tenant,$service]=$this->store->scope();
        $secret=$this->cipher->decryptFor($snapshot['secret_encrypted'],$tenant,$service,'messaging:'.$id);
        // Keep provider I/O outside the transaction; compare the revision again before accepting proof.
        try {$proof=$snapshot['channel']==='whatsapp'?$this->lookup->proveWhatsApp($snapshot['account_sid'],$secret,$snapshot['sender'],$input->senderSid):$this->lookup->proveSms($snapshot['account_sid'],$secret,$snapshot['sender']);}
        catch(Forbidden $error) {
            $this->store->atomic(function()use($id,$input):void {
                $row=$this->connection($id,true);
                if ((int)$row['version_number']!==$input->version || $row['status']!=='verified') return;
                $this->store->execute("UPDATE nexa_messaging_connection SET status='unverified',dispatch_enabled=0,verified_at=NULL,provider_resource_sid=NULL,version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$id]);
                $this->audit($id,'messaging.connection_verification_revoked',['dispatchEnabled'=>false]);
            },true);
            throw $error;
        }
        return $this->store->atomic(function()use($id,$input,$proof):array {
            $row=$this->connection($id,true);
            if (!$this->currentAdministrator(true)) throw new Forbidden('An active tenant administrator and service are required.');
            if ((int)$row['version_number']!==$input->version || $row['status']==='disabled') throw new Conflict('The connection changed during verification. Refresh and try again.');
            try {
                $this->store->execute("UPDATE nexa_messaging_connection SET status='verified',verified_at=UTC_TIMESTAMP(),provider_resource_sid=?,version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$proof['phoneSid'],...$this->store->scope(),$id]);
            } catch(PDOException $error){if(($error->errorInfo[1]??null)===1062)throw new Conflict('This verified sender is already assigned. Contact your platform operator.');throw $error;}
            $this->audit($id,'messaging.connection_verified',['providerResourceSid'=>$proof['phoneSid'],'dispatchEnabled'=>false]);
            return ['id'=>$id,'status'=>'verified','version'=>$input->version+1,'dispatchEnabled'=>false];
        },true);
    }
    /** Internal staging only. Dispatch/activation is deliberately not exposed. */
    public function stage(string $connectionId,string $contactId,string $body,string $requestKey): array
    {
        $this->access();ChatStore::uuidValue($connectionId);ChatStore::uuidValue($requestKey);
        return $this->store->atomic(function()use($connectionId,$contactId,$body,$requestKey):array {
            $connection=$this->connection($connectionId,true);
            if ($connection['status']==='disabled') throw new Forbidden('Messaging connection is disabled.');
            $contact=$this->records->createForUser('Contact',$this->user)->getEntity($contactId);
            if (!$contact || [$contact->get('tenantId'),$contact->get('serviceId')]!==$this->store->scope() || !$this->acl->checkEntity($contact,'read') || !$this->acl->checkField('Contact','phoneNumber','read')) throw new NotFound('Contact unavailable.');
            $this->restrictions->assertContactAllowed($contactId,$connection['channel']);
            $phone=(string)$contact->get('phoneNumber');$recipient=$connection['channel']==='whatsapp'?'whatsapp:'.$phone:$phone;
            if($connection['channel']==='sms')$this->preferences->assertAllowed($connectionId,$recipient);
            (new TwilioMessagingAdapter())->outbound($connection['account_sid'],$connection['channel'],$connection['sender'],$recipient,$body,'https://example.invalid/status');
            $intent=hash('sha256',json_encode([$connectionId,$contactId,$recipient,$body],JSON_THROW_ON_ERROR));
            $old=$this->store->rows('SELECT id,intent_hash,status FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND request_key=?',[...$this->store->scope(),$requestKey])[0]??null;
            if ($old) {if(!hash_equals($old['intent_hash'],$intent))throw new Conflict('This message request key was already used.');return ['id'=>$old['id'],'status'=>$old['status'],'duplicate'=>true];}
            $count=$this->store->rows('SELECT id FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? LIMIT 1000',$this->store->scope());
            if(count($count)>=1000)throw new BadRequest('The workspace held-message limit has been reached.');
            $id=ChatStore::uuid();
            $this->store->execute('INSERT INTO nexa_messaging_outbox (id,tenant_id,service_id,connection_id,contact_id,request_key,intent_hash,body,recipient,connection_version,created_by_id,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',[$id,...$this->store->scope(),$connectionId,$contactId,$requestKey,$intent,$body,$recipient,$connection['version_number'],$this->user->getId()]);
            $this->audit($id,'messaging.message_held',['connectionId'=>$connectionId]);
            return ['id'=>$id,'status'=>'held','duplicate'=>false];
        },true);
    }
    private function audit(string $id,string $action,array $metadata): void
    {
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,?,?, ?,?,'messaging-settings',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$action,str_starts_with($action,'messaging.connection_')?'NexaMessagingConnection':'NexaMessagingOutbox',$id,ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
}
