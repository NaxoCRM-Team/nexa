<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Core\{Application,AclManager,InjectableFactory};
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext,TenantContextStore};
use Espo\Core\Utils\Config;
use Espo\Core\Exceptions\{Forbidden,NotFound};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Custom\Tools\Conversation\{ChatStore,ChatService,PublicChatService,MessagingCallbackService,MessagingConversationService,MessengerWebhookService,MessengerService};
use Espo\Custom\Tools\Customer\CustomerFoundationQueryService;
use Espo\Custom\Tools\Automation\WorkflowTriggerSource;

$names=['NEXA_SECRET_ACTIVE_KEY_ID','NEXA_SECRET_KEYS'];$previous=[];foreach($names as $name)$previous[$name]=getenv($name);
putenv('NEXA_SECRET_ACTIVE_KEY_ID=combined-fixture');putenv('NEXA_SECRET_KEYS='.json_encode(['combined-fixture'=>base64_encode(random_bytes(32))]));
$app=new Application();$app->setupSystemUser();$c=$app->getContainer();$em=$c->getByClass(EntityManager::class);
$factory=$c->getByClass(InjectableFactory::class);$contexts=$c->getByClass(TenantContextStore::class);$store=$factory->create(ChatStore::class);$cipher=$factory->create(ProviderSecretCipher::class);
$fixtureSuffix=bin2hex(random_bytes(6));
$a=new TenantContext(ChatStore::uuid(),'phase8-cross-'.$fixtureSuffix.'-a','phase8-combined');
$b=new TenantContext(ChatStore::uuid(),'phase8-cross-'.$fixtureSuffix.'-b','phase8-combined');$call=fn($fn)=>$contexts->runWith($a,$fn);
$checks=0;$assert=function(bool $ok,string $why)use(&$checks){$checks++;if(!$ok)throw new RuntimeException($why);};
$deny=function(callable $fn,string $why)use($assert){try{$fn();}catch(Forbidden|NotFound $error){$assert(true,$why);return;}$assert(false,$why);};
$config=new class extends Config{public function __construct(){}public function get(string $key,$default=null){return $key==='nexaMessagingPublicBaseUrl'?'https://combined.example/nexa':$default;}};
$tx=$em->getTransactionManager();$tx->start();
try{
    foreach([$a,$b] as $ctx){
        $store->execute("INSERT INTO nexa_tenant (id,slug,display_name,status,region,timezone) VALUES (?,?,'Rollback cross-channel fixture','active','local','UTC')",[$ctx->tenantId,$ctx->slug]);
        $store->execute("INSERT INTO nexa_tenant_service (tenant_id,service_id,status) VALUES (?,?,'active')",[$ctx->tenantId,$ctx->serviceId]);
    }
    $suffix=bin2hex(random_bytes(6));$u=$call(fn()=>$em->createEntity('User',['userName'=>'combined-'.$suffix,'emailAddress'=>'combined-'.$suffix.'@example.test','type'=>'admin','isActive'=>true]));$u=$call(fn()=>$em->getEntityById('User',$u->getId()));$acl=$c->getByClass(AclManager::class)->createUserAcl($u);
    $build=fn($class,$extra=[])=>$factory->createWith($class,['user'=>$u,'acl'=>$acl,...$extra]);
    $contact=$call(fn()=>$em->createEntity('Contact',['firstName'=>'Combined','lastName'=>$suffix,'phoneNumber'=>'+14155550101']));
    $chat=$build(ChatService::class);$public=$factory->create(PublicChatService::class);$visitor=ChatStore::uuid();$token=bin2hex(random_bytes(32));$origin='http://localhost:8099';
    $widget=$call(fn()=>$chat->process((object)['op'=>'widgetSave','name'=>'Combined '.$suffix,'title'=>'Support','welcome'=>'Welcome','origins'=>[$origin],'color'=>'#147d70','position'=>'right','draggable'=>true,'enabled'=>true,'privacyUrl'=>'']));
    $key=$call(fn()=>$store->rows('SELECT public_key FROM nexa_chat_widget WHERE tenant_id=? AND service_id=? AND id=?',[...$store->scope(),$widget['id']]))[0]['public_key'];
    $opened=$public->process($key,$origin,$token,(object)['op'=>'start','nonce'=>ChatStore::uuid(),'visitorKey'=>$visitor,'body'=>'Combined website history']);
    $identity=ChatStore::uuid();$call(fn()=>$store->execute('INSERT INTO nexa_visitor_identity (id,tenant_id,service_id,visitor_key_hash,contact_id,first_seen_at,last_seen_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))',[$identity,...$store->scope(),hash('sha256',$a->tenantId."\0".$a->serviceId."\0".$visitor),$contact->getId()]));
    $call(fn()=>$store->execute("INSERT INTO nexa_personalization_identity_proof (tenant_id,service_id,visitor_identity_id,issuer_user_id,contact_id,proof_method,evidence_hash,verification_event_id,verified_at,expires_at) VALUES (?,?,?,?,?,'authenticated_session',?,?,UTC_TIMESTAMP(6),DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 1 HOUR))",[...$store->scope(),$identity,$u->getId(),$contact->getId(),hash('sha256','combined fixture'),ChatStore::uuid()]));
    $call(fn()=>$chat->process((object)['op'=>'identify','id'=>$opened['id'],'visitorKey'=>$visitor]));$ids=['chat'=>$opened['id']];$adapters=[];
    $callbacks=$factory->createWith(MessagingCallbackService::class,['config'=>$config]);
    foreach(['sms','whatsapp','messenger'] as $channel){
        $connection=ChatStore::uuid();$secret=bin2hex(random_bytes(16));$account='AC'.bin2hex(random_bytes(16));
        $sender=$channel==='messenger'?(string)random_int(100000000,999999999):($channel==='whatsapp'?'whatsapp:':'').'+1415'.random_int(1000000,9999999);
        $proof=$channel==='messenger'?$sender:($channel==='sms'?'PN':'XE').bin2hex(random_bytes(16));
        $encrypted=$cipher->encryptFor($secret,$a->tenantId,$a->serviceId,'messaging:'.$connection);
        $appSecret=$cipher->encryptFor($secret,$a->tenantId,$a->serviceId,'messenger-app:'.$connection);
        $call(fn()=>$store->execute("INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,channel,provider,status,account_sid,sender,secret_encrypted,created_by_id,verified_at,provider_resource_sid,messenger_app_id,messenger_app_secret,messenger_verify_hash,messenger_webhook_verified_at) VALUES (?,?,?,'Combined fixture',?,?,'verified',?,?,?,?,UTC_TIMESTAMP(),?,'999',?,?,UTC_TIMESTAMP())",[$connection,...$store->scope(),$channel,$channel==='messenger'?'meta':'twilio',$account,$sender,$encrypted,$u->getId(),$proof,$appSecret,hash('sha256','fixture')]));
        if($channel==='messenger'){
            $webhook=$factory->create(MessengerWebhookService::class);$payload=json_encode(['object'=>'page','entry'=>[['id'=>$sender,'messaging'=>[['sender'=>['id'=>'222'],'recipient'=>['id'=>$sender],'timestamp'=>time()*1000,'message'=>['mid'=>'mid.'.bin2hex(random_bytes(12)),'text'=>'Combined messenger history']]]]]]);
            $receive=fn()=>$webhook->receive($connection,$payload,'sha256='.hash_hmac('sha256',$payload,$secret));
            $assert($receive()['accepted']===1&&$receive()['accepted']===0,'Signed Messenger receipt is accepted once.');$adapter=$build(MessengerService::class);
        }else{
            $params=['AccountSid'=>$account,'MessageSid'=>'SM'.bin2hex(random_bytes(16)),'From'=>($channel==='whatsapp'?'whatsapp:':'').'+14155550101','To'=>$sender,'Body'=>'Combined '.$channel.' history','NumMedia'=>'0'];ksort($params,SORT_STRING);$signed=$callbacks->callbackUrl($connection,'inbound');foreach($params as $name=>$value)$signed.=$name.$value;
            $receive=fn()=>$callbacks->process($connection,'inbound',http_build_query($params),'application/x-www-form-urlencoded',base64_encode(hash_hmac('sha1',$signed,$secret,true)));
            $assert(!$receive()['duplicate']&&$receive()['duplicate'],'Signed '.$channel.' receipt is accepted once.');$adapter=$build(MessagingConversationService::class,['channel'=>$channel]);
        }
        if($channel!=='messenger')$call(fn()=>$factory->create(\Espo\Custom\Tools\Conversation\MessagingConversationProjector::class)->recover());
        $list=$call(fn()=>$store->rows('SELECT id FROM nexa_messaging_conversation WHERE tenant_id=? AND service_id=? AND connection_id=?',[...$store->scope(),$connection]));$id=$list[0]['id'];$thread=$call(fn()=>$adapter->process((object)['op'=>'thread','id'=>$id]));
        $call(fn()=>$adapter->process((object)['op'=>'linkContact','id'=>$id,'version'=>$thread['conversation']['version'],'contactId'=>$contact->getId()]));$ids[$channel]=$id;$adapters[$channel]=$adapter;
    }
    $snapshot=$build(CustomerFoundationQueryService::class);$data=$call(fn()=>$snapshot->getSnapshot('Contact',$contact->getId()));
    $assert(count($data['liveChat'])===1,'Verified website chat appears on the same Contact.');
    $channels=array_column($data['messaging'],'channel');sort($channels);
    $assert($channels===['messenger','sms','whatsapp'],'One Contact foundation contains all three original messaging channels without duplicates.');
    $trigger=$factory->create(WorkflowTriggerSource::class);$events=$call(fn()=>$trigger->events(['event'=>'conversation.received'],'2026-01-01 00:00:00',ChatStore::uuid()));
    foreach($ids as $channel=>$id)$assert(count(array_filter($events,fn($e)=>$e['source_id']===$id&&$e['contact_id']===$contact->getId()))===1,'Governed automation discovers exactly one '.$channel.' event for the Contact.');
    $deny(fn()=>$contexts->runWith($b,fn()=>$snapshot->getSnapshot('Contact',$contact->getId())),'A foreign context cannot retrieve the combined Contact snapshot.');
    $staff=$call(fn()=>$em->createEntity('User',['userName'=>'combined-hidden-'.$suffix,'emailAddress'=>'combined-hidden-'.$suffix.'@example.test','type'=>'regular','isActive'=>true]));
    $role=$call(fn()=>$em->createEntity('Role',['name'=>'Combined Contact only','data'=>['Contact'=>['read'=>'all'],'NexaChatConversation'=>['read'=>'no'],'NexaMessagingConversation'=>['read'=>'no']]]));$call(fn()=>$em->getRDBRepository('User')->getRelation($staff,'roles')->relate($role));$staff=$call(fn()=>$em->getEntityById('User',$staff->getId()));
    $restricted=$factory->createWith(CustomerFoundationQueryService::class,['user'=>$staff,'acl'=>$c->getByClass(AclManager::class)->createUserAcl($staff)]);$hidden=$call(fn()=>$restricted->getSnapshot('Contact',$contact->getId()));
    $assert($hidden['messaging']===[]&&$hidden['liveChat']===[],'Contact access alone cannot disclose any channel history.');
    foreach($adapters as $channel=>$adapter){$thread=$call(fn()=>$adapter->process((object)['op'=>'thread','id'=>$ids[$channel]]));$call(fn()=>$adapter->process((object)['op'=>'linkContact','id'=>$ids[$channel],'version'=>$thread['conversation']['version'],'contactId'=>null]));}
    $call(fn()=>$store->execute('UPDATE nexa_personalization_identity_proof SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND visitor_identity_id=?',[...$store->scope(),$identity]));
    $unlinked=$call(fn()=>$snapshot->getSnapshot('Contact',$contact->getId()));$assert($unlinked['messaging']===[]&&$unlinked['liveChat']===[],'Unlink/revocation removes all channel projections without stale copied content.');
    echo "Cross-channel Contact acceptance passed: {$checks} (signed local receipts; rollback-only; no provider calls).\n";
}finally{$tx->rollback();foreach($previous as $name=>$value)$value===false?putenv($name):putenv($name.'='.$value);}
