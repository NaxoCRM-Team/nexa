<?php
declare(strict_types=1);
// Included only after the CLI opt-in and synthetic-tenant ownership checks.
if($container->getByClass(\Espo\Core\Utils\Config::class)->get('nexaSmsDispatchEnabled')===true)throw new RuntimeException('Acceptance fixtures require real deployment SMS sending to remain disabled.');
$smsConfig=new class extends \Espo\Core\Utils\Config {
    public function __construct(){}
    public function get(string $name,$default=null){return ['nexaSmsDispatchEnabled'=>true,'nexaMessagingPublicBaseUrl'=>'https://sms-acceptance.example/nexa','nexaSmsAttemptsPerMinute'=>2][$name]??$default;}
};
$smsCallbacks=$factory->createWith(\Espo\Custom\Tools\Conversation\MessagingCallbackService::class,['config'=>$smsConfig]);
$smsOutbox=$factory->createWith(\Espo\Custom\Tools\Conversation\SmsOutboxService::class,['user'=>$user,'acl'=>$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user),'config'=>$smsConfig,'callbacks'=>$smsCallbacks]);
if($command==='whatsapp-settings-proof'){
    $connection=$execute("SELECT id,version_number FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND channel='whatsapp'",[$tenant['id'],$tenant['serviceId'],$input['connectionId']??''])->fetch(PDO::FETCH_ASSOC);
    if(!$connection)throw new RuntimeException('Synthetic WhatsApp connection unavailable.');
    $lookup=new class extends \Espo\Custom\Tools\Conversation\TwilioMessagingLookup {public function proveWhatsApp(string $account,string $secret,string $sender,string $sid):array{return ['phoneSid'=>$sid];}};
    $settings=$factory->createWith(\Espo\Custom\Tools\Conversation\MessagingConnectionService::class,['user'=>$user,'acl'=>$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user),'lookup'=>$lookup]);
    return $settings->process((object)['op'=>'verify','id'=>$connection['id'],'version'=>(int)$connection['version_number'],'senderSid'=>'XE'.str_repeat('7',32)]);
}
if($command==='sms-dispatch-seed'){
    $contact=$em->getEntityById('Contact',$tenant['contactId']);$contact->set('phoneNumber','+14155550101');$em->saveEntity($contact);
    $consent=$factory->createWith(\Espo\Custom\Tools\Consent\ConsentService::class,['user'=>$user,'acl'=>$container->getByClass(\Espo\Core\AclManager::class)->createUserAcl($user)]);
    $purpose=array_values(array_filter($consent->getWorkspace()['purposes'],fn($p)=>$p['purposeKey']==='customer_service'))[0]['id'];
    $consent->recordDecision((object)['contactId'=>$contact->getId(),'purposeId'=>$purpose,'channel'=>'sms','status'=>'granted','legalBasis'=>'FreelyGivenConsent','source'=>'manual','evidenceNote'=>'Synthetic SMS acceptance consent.']);
    $cipher=$factory->create(\Espo\Custom\Tools\Auth\ProviderSecretCipher::class);$connection=\Espo\Custom\Tools\Conversation\ChatStore::uuid();$secret=bin2hex(random_bytes(16));$sender='+1415'.random_int(1000000,9999999);
    $execute("INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,channel,account_sid,sender,secret_encrypted,status,created_by_id,verified_at,provider_resource_sid,dispatch_enabled) VALUES (?,?,?,'Synthetic SMS sender','sms',?,?,?,'verified',?,UTC_TIMESTAMP(),?,1)",[$connection,$tenant['id'],$tenant['serviceId'],'AC'.bin2hex(random_bytes(16)),$sender,$cipher->encryptFor($secret,$tenant['id'],$tenant['serviceId'],'messaging:'.$connection),$user->getId(),'PN'.bin2hex(random_bytes(16))]);
    $root=$em->createEntity('NexaMessagingConversation',['name'=>'Synthetic SMS sender','status'=>'Open','contactId'=>$contact->getId(),'connectionId'=>$connection,'peerHash'=>hash('sha256',$connection."\0".'+14155550101'),'peerEncrypted'=>$cipher->encryptFor('+14155550101',$tenant['id'],$tenant['serviceId'],'messaging-peer:'.$connection)]);
    $ids=[];
    for($i=0;$i<max(0,min(6,(int)($input['count']??4)));$i++)$ids[]=$smsOutbox->prepare((object)['op'=>'prepareSms','id'=>$root->getId(),'version'=>(int)$root->get('versionNumber'),'requestKey'=>\Espo\Custom\Tools\Conversation\ChatStore::uuid(),'purposeId'=>$purpose,'body'=>'Synthetic queued SMS '.$i,'queue'=>true])['id'];
    if(($input['review']??false)===true){
        $account=$execute('SELECT account_sid FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=?',[$tenant['id'],$tenant['serviceId'],$connection])->fetchColumn();
        foreach(['STOP','START'] as $word){
            $params=['AccountSid'=>$account,'MessageSid'=>'SM'.bin2hex(random_bytes(16)),'From'=>'+14155550101','To'=>$sender,'Body'=>$word,'NumMedia'=>'0','OptOutType'=>$word];
            $url=$smsCallbacks->callbackUrl($connection,'inbound');ksort($params,SORT_STRING);$signed=$url;foreach($params as $key=>$value)$signed.=$key.$value;
            $smsCallbacks->process($connection,'inbound',http_build_query($params),'application/x-www-form-urlencoded',base64_encode(hash_hmac('sha1',$signed,$secret,true)));sleep(1);
        }
        $consent->recordDecision((object)['contactId'=>$contact->getId(),'purposeId'=>$purpose,'channel'=>'sms','status'=>'granted','legalBasis'=>'FreelyGivenConsent','source'=>'manual','evidenceNote'=>'Synthetic new explicit consent after signed START.']);
    }
    return ['rootId'=>$root->getId(),'purposeId'=>$purpose,'ids'=>$ids];
}
if($command==='sms-dispatch-inspect')return [
    'rows'=>$execute('SELECT id,status,delivery_status,attempts FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? ORDER BY created_at,id',[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
    'posts'=>$execute("SELECT subject_id,COUNT(*) AS count FROM nexa_audit_event WHERE tenant_id=? AND service_id=? AND action='sms.acceptance_post' GROUP BY subject_id",[$tenant['id'],$tenant['serviceId']])->fetchAll(PDO::FETCH_ASSOC),
];
if($command==='sms-dispatch-release'){
    $row=$execute('SELECT * FROM nexa_messaging_outbox WHERE tenant_id=? AND service_id=? AND id=?',[$tenant['id'],$tenant['serviceId'],$input['outboxId']])->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException('Synthetic outbox unavailable.');
    return $smsOutbox->change((object)['op'=>'releaseSms','id'=>$row['conversation_id'],'outboxId'=>$row['id'],'version'=>(int)$row['version_number']]);
}
$smsTransport=new class($execute,$tenant) extends \Espo\Custom\Tools\Conversation\TwilioSmsTransport {
    public int $posts=0;
    public function __construct(private Closure $execute,private array $tenant){}
    public function send(array $connection,string $secret,array $message,string $callback):array{
        $this->posts++;$attempt=basename($callback);$execute=$this->execute;
        $outbox=$execute('SELECT outbox_id FROM nexa_sms_attempt WHERE tenant_id=? AND service_id=? AND id=?',[$this->tenant['id'],$this->tenant['serviceId'],$attempt])->fetchColumn();
        if(!$outbox)throw new RuntimeException('Synthetic submission must have a committed attempt.');
        $execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (UUID(),?,?,'system','sms.acceptance_post','NexaMessagingOutbox',?,?,'sms-acceptance','{}',UTC_TIMESTAMP(6))",[$this->tenant['id'],$this->tenant['serviceId'],$outbox,$attempt]);
        usleep(200000);return ['outcome'=>'accepted','sid'=>'SM'.bin2hex(random_bytes(16)),'status'=>'queued'];
    }
    public function inspect(array $connection,string $secret,string $sid,array $message):?array{return ['sid'=>$sid,'status'=>'delivered'];}
};
$smsWorker=$factory->createWith(\Espo\Custom\Tools\Conversation\SmsDispatchWorker::class,['config'=>$smsConfig,'callbacks'=>$smsCallbacks,'transport'=>$smsTransport]);
if($command==='sms-dispatch-poll')$execute("UPDATE nexa_messaging_outbox SET submitted_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 11 MINUTE) WHERE tenant_id=? AND service_id=? AND id=? AND status='submitted'",[$tenant['id'],$tenant['serviceId'],$input['outboxId']]);
$factory->createWith(\Espo\Custom\Jobs\DispatchSms::class,['worker'=>$smsWorker])->run();return ['posts'=>$smsTransport->posts];
