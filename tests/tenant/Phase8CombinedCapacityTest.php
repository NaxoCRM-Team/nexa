<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__, 2).'/espocrm/bootstrap.php';

use Espo\Core\{Application,AclManager,InjectableFactory};
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Job\{Job,QueueProcessor};
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\QueueProcessor\Params;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext,TenantContextStore,PlatformExecutionGateway};
use Espo\Custom\Tools\Conversation\{ChatStore,ChatService,ConversationRoutingService};

set_exception_handler(static function(Throwable $error):void {
    fwrite(STDERR,$error->getMessage()."\n".$error->getTraceAsString()."\n"); exit(1);
});

final class Phase8RoutingLoadProbe implements Job
{
    public static array $runs=[];
    public function __construct(private ConversationRoutingService $routing,private ChatStore $store) {}
    public function run(Data $data):void
    {
        $start=microtime(true);$count=$this->routing->run();
        self::$runs[]=['tenant'=>$this->store->scope()[0],'count'=>$count,'seconds'=>microtime(true)-$start];
        // Simulate completed work between rounds; never touch a non-fixture tenant.
        foreach(['nexa_chat_conversation','nexa_messaging_conversation'] as $table)
            $this->store->execute("UPDATE $table SET status='Closed' WHERE tenant_id=? AND service_id=? AND assigned_user_id IS NOT NULL",$this->store->scope());
    }
}

$app=new Application();$app->setupSystemUser();$c=$app->getContainer();
$em=$c->getByClass(EntityManager::class);$factory=$c->getByClass(InjectableFactory::class);
$contexts=$c->getByClass(TenantContextStore::class);$platform=$c->getByClass(PlatformExecutionGateway::class);
$store=$factory->create(ChatStore::class);$worker=$factory->create(ConversationRoutingService::class);
$checks=0;$timings=[];$started=microtime(true);$peak=memory_get_usage(true);
$assert=static function(bool $ok,string $message)use(&$checks):void{$checks++;if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $fn,string $expected)use($assert):void {
    try{$fn();}catch(BadRequest $error){$assert($error->getMessage()===$expected,'Wrong capacity rejection: '.$error->getMessage());return;}
    $assert(false,'Capacity overflow was accepted: '.$expected);
};
$tx=$em->getTransactionManager();$tx->start();
try {
    $suffix=bin2hex(random_bytes(6));$tenants=[];$agents=[];$services=[];
    for($i=0;$i<3;$i++){
        $id=ChatStore::uuid();$slug='phase8-load-'.$suffix.'-'.$i;
        $store->execute("INSERT INTO nexa_tenant (id,slug,display_name,status,region,timezone) VALUES (?,?,'Rollback Phase 8 load','active','local','UTC')",[$id,$slug]);
        $store->execute("INSERT INTO nexa_tenant_service (tenant_id,service_id,status) VALUES (?,?,'active')",[$id,TenantContext::CRM_SERVICE_ID]);
        $ctx=new TenantContext($id,$slug,'phase8-capacity');$tenants[]=$ctx;
        $contexts->runWith($ctx,function()use($em,$factory,$c,$store,$suffix,$i,&$agents,&$services){
            $u=$em->createEntity('User',['userName'=>'p8-'.$suffix.'-'.$i,'emailAddress'=>'p8-'.$suffix.'-'.$i.'@example.test','lastName'=>'Load agent','type'=>'admin','isActive'=>true]);
            $u=$em->getEntityById('User',$u->getId());$agents[]=$u;
            $routing=$factory->createWith(ConversationRoutingService::class,['user'=>$u]);
            foreach(ConversationRoutingService::CHANNELS as $channel)$routing->process((object)['op'=>'save','channel'=>$channel,'teamId'=>null,'enabled'=>true,'capacity'=>50,'version'=>0]);
            $routing->process((object)['op'=>'presence','available'=>true,'version'=>0]);
            $services[]=$factory->createWith(ChatService::class,['user'=>$u,'acl'=>$c->getByClass(AclManager::class)->createUserAcl($u)]);
        });
    }
    $a=$tenants[0];$b=$tenants[1];$call=fn($fn)=>$contexts->runWith($a,$fn);
    $root=fn()=>$em->createEntity('NexaChatConversation',['name'=>'Capacity chat','status'=>'Open','widgetId'=>ChatStore::uuid(),'origin'=>'http://localhost','tokenHash'=>bin2hex(random_bytes(32)),'visitorKeyHash'=>bin2hex(random_bytes(32)),'startNonce'=>ChatStore::uuid(),'expiresAt'=>gmdate('Y-m-d H:i:s',time()+3600)]);
    $start=microtime(true);$historyRoot=$call($root);$bRoot=$contexts->runWith($b,$root);
    $insert=$em->getPDO()->prepare("INSERT INTO nexa_chat_message (tenant_id,service_id,conversation_id,nonce,author_kind,body,created_at) VALUES (?,?,?,?,'visitor','Capacity evidence',UTC_TIMESTAMP())");
    $roots=[$historyRoot];for($i=1;$i<10;$i++)$roots[]=$call($root);
    foreach($roots as $index=>$r)for($n=0;$n<($index===0?999:1000);$n++)$insert->execute([$a->tenantId,$a->serviceId,$r->getId(),ChatStore::uuid()]);
    $nonce=ChatStore::uuid();$max=str_repeat('x',4000);
    $last=$call(fn()=>$store->atomic(fn()=>$store->append($historyRoot,$nonce,$max),true));
    $assert(!$last['duplicate'],'The 1,000th conversation message and 10,000th workspace-hour message are accepted.');
    $assert($call(fn()=>$store->atomic(fn()=>$store->append($historyRoot,$nonce,$max),true))['duplicate'],'Idempotent replay still succeeds at full capacity.');
    $reject(fn()=>$call(fn()=>$store->atomic(fn()=>$store->append($historyRoot,ChatStore::uuid(),'overflow'),true)),'This conversation has reached its message limit.');
    $fresh=$call($root);
    $reject(fn()=>$call(fn()=>$store->atomic(fn()=>$store->append($fresh,ChatStore::uuid(),'overflow'),true)),'The workspace chat message limit has been reached. Try again later.');
    $reject(fn()=>$call(fn()=>$store->atomic(fn()=>$store->append($fresh,ChatStore::uuid(),str_repeat('x',4001)),true)),'Enter a message of up to 4,000 characters.');
    $assert(!$contexts->runWith($b,fn()=>$store->atomic(fn()=>$store->append($bRoot,ChatStore::uuid(),'Independent tenant'),true))['duplicate'],'A full tenant cannot consume another tenant message allowance.');
    $seen=[];$cursor='';$pages=0;
    do{
        $page=$call(fn()=>$store->history($historyRoot->getId(),'',$cursor));$assert(count($page['messages'])<=100,'History respects its 100-message response bound.');
        foreach($page['messages'] as $message){$assert(!isset($seen[$message['id']]),'History cursor must not repeat a message.');$seen[$message['id']]=true;}
        $cursor=$page['messages'][0]['id'];$pages++;
        if($pages>10)throw new RuntimeException('History cursor did not terminate.');
    }while($page['hasMore']);
    $assert(count($seen)===1000&&$pages===10,'All 1,000 stored messages remain reachable in ten bounded pages.');
    $call(fn()=>$store->execute('UPDATE nexa_chat_message SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 61 MINUTE) WHERE tenant_id=? AND service_id=? AND id=?',[...$store->scope(),$last['id']]));
    $assert(!$call(fn()=>$store->atomic(fn()=>$store->append($fresh,ChatStore::uuid(),'Recovered capacity'),true))['duplicate'],'The rolling hourly allowance recovers as evidence ages out.');
    $widget=(object)['op'=>'widgetSave','name'=>'Capacity widget','title'=>'Support','welcome'=>'Welcome','origins'=>['http://localhost:8099'],'color'=>'#147d70','position'=>'right','draggable'=>true,'enabled'=>false,'privacyUrl'=>''];
    for($i=0;$i<10;$i++)$call(fn()=>$services[0]->process($widget));
    $assert(count($call(fn()=>$services[0]->process((object)['op'=>'widgets']))['list'])===10,'All ten supported widgets are returned.');
    $reject(fn()=>$call(fn()=>$services[0]->process($widget)),'The workspace supports up to ten chat widgets.');
    for($i=count($roots)+1;$i<101;$i++)$call($root);
    $page=$call(fn()=>$services[0]->process((object)['op'=>'list']));
    $assert(count($page['list'])===100&&$page['nextCursor']!==null,'A 101-chat inbox returns a bounded first page and cursor.');
    $next=$call(fn()=>$services[0]->process((object)['op'=>'list','before'=>$page['nextCursor']]));
    $assert(count($next['list'])===1&&$next['nextCursor']===null,'The final inbox row remains reachable without pagination loss.');
    $timings['capacitySeconds']=round(microtime(true)-$start,3);
    // Retire volume fixtures before routing, without deleting any real tenant data.
    $call(fn()=>$store->execute("UPDATE nexa_chat_conversation SET status='Closed' WHERE tenant_id=? AND service_id=?",$store->scope()));
    $contexts->runWith($b,fn()=>$store->execute("UPDATE nexa_chat_conversation SET status='Closed' WHERE tenant_id=? AND service_id=?",$store->scope()));
    $makeBacklog=function(TenantContext $ctx,int $perChannel)use($contexts,$em,$store,$root):void{
        $contexts->runWith($ctx,function()use($em,$store,$root,$perChannel){
            foreach(ConversationRoutingService::CHANNELS as $channel){
                $connection=ChatStore::uuid();
                if($channel!=='chat'){
                    $sender=$channel==='messenger'?(string)random_int(100000000,999999999):'+1415'.random_int(1000000,9999999);
                    $proof=$channel==='messenger'?$sender:($channel==='sms'?'PN':'XE').bin2hex(random_bytes(16));
                    $store->execute("INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,channel,provider,status,account_sid,sender,secret_encrypted,created_by_id,verified_at,provider_resource_sid,messenger_app_id,messenger_app_secret,messenger_verify_hash) VALUES (?,?,?,'Routing load',?,?,'verified',?,?,'not-a-provider-credential','system',UTC_TIMESTAMP(),?,'999','not-a-provider-secret',?)",[$connection,...$store->scope(),$channel,$channel==='messenger'?'meta':'twilio','AC'.bin2hex(random_bytes(16)),$sender,$proof,str_repeat('a',64)]);
                }
                for($i=0;$i<$perChannel;$i++){
                    if($channel==='chat'){$r=$root();$r->set('handoffRequestedAt',gmdate('Y-m-d H:i:s'));$em->saveEntity($r);}
                    else $em->createEntity('NexaMessagingConversation',['name'=>'Routing '.$channel,'status'=>'Open','connectionId'=>$connection,'peerHash'=>bin2hex(random_bytes(32)),'peerEncrypted'=>'synthetic-routing-only']);
                }
            }
        });
    };
    $start=microtime(true);$makeBacklog($a,40);$makeBacklog($b,20);$makeBacklog($tenants[2],20);
    $renew=fn($ctx)=>$contexts->runWith($ctx,fn()=>$store->execute('UPDATE nexa_conversation_presence SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 SECOND) WHERE tenant_id=? AND service_id=?',$store->scope()));
    for($i=0;$i<3;$i++){$renew($a);$n=$call(fn()=>$worker->run());$assert($n===($i<2?20:10),'Routing enforces its 20-attempt batch and maximum 50 active records per agent.');}
    $assert($call(fn()=>$worker->run())===0,'A full agent cannot receive the 51st active record.');
    foreach(['nexa_chat_conversation','nexa_messaging_conversation'] as $table)$call(fn()=>$store->execute("UPDATE $table SET assigned_user_id=NULL,routing_state='unrouted',routing_checked_at=NULL WHERE tenant_id=? AND service_id=?",$store->scope()));
    $queue='phase8-'.$suffix;$processor=$factory->create(QueueProcessor::class);
    foreach($tenants as $ctx)for($i=0;$i<8;$i++)$contexts->runWith($ctx,fn()=>$em->createEntity('Job',['name'=>'Phase 8 sustained routing','queue'=>$queue,'status'=>'Pending','executeTime'=>gmdate('Y-m-d H:i:s',time()-60),'className'=>Phase8RoutingLoadProbe::class]));
    $params=Params::create()->withQueue($queue)->withLimit(2);$counts=[];
    for($round=0;$round<12;$round++){
        foreach($tenants as $ctx)$renew($ctx);$before=count(Phase8RoutingLoadProbe::$runs);
        $platform->run('Rollback-only Phase 8 routing fairness',fn()=>$processor->process($params));
        $batch=array_slice(Phase8RoutingLoadProbe::$runs,$before);
        $assert(count($batch)===2&&count(array_unique(array_column($batch,'tenant')))===2,'A busy tenant cannot monopolize both native worker slots.');
        foreach($batch as $run){$assert($run['count']<=20,'Every native routing job remains bounded.');$counts[$run['tenant']]=($counts[$run['tenant']]??0)+1;}
        if($round===1)$assert(count($counts)===3,'Every tenant makes progress within two two-slot rounds.');
    }
    foreach($tenants as $ctx){
        $assert($counts[$ctx->tenantId]===8,'Sustained native scheduling gives every tenant eight turns.');
        $contexts->runWith($ctx,function()use($store,$assert){
            foreach(['nexa_chat_conversation','nexa_messaging_conversation'] as $table)$assert((int)$store->rows("SELECT COUNT(*) n FROM $table WHERE tenant_id=? AND service_id=? AND status='Open' AND deleted=0",$store->scope())[0]['n']===0,'All four channel backlogs drain without starvation.');
        });
    }
    $timings['routingSeconds']=round(microtime(true)-$start,3);
    $timings['workerMaxSeconds']=round(max(array_column(Phase8RoutingLoadProbe::$runs,'seconds')),3);
    $timings['totalSeconds']=round(microtime(true)-$started,3);
    $timings['peakMemoryMiB']=round((memory_get_peak_usage(true)-$peak)/1048576,2);
    echo json_encode(['checks'=>$checks,'timings'=>$timings,'messageRows'=>10001,'routingBacklog'=>320,'nativeWorkerRounds'=>12,'tenants'=>3,'storage'=>'rollback-only; no provider calls'],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT)."\n";
}finally{if($tx->isStarted())$tx->rollback();}
