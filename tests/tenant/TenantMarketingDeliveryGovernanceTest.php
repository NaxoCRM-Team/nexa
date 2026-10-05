<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);require $root.'/espocrm/bootstrap.php';
use Espo\Core\Application;use Espo\Core\InjectableFactory;use Espo\Core\ORM\EntityManager;use Espo\Core\Tenant\TenantContext;use Espo\Core\Tenant\TenantContextStore;use Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService;use Espo\Custom\Tools\MarketingEmail\PostalCallbackService;
$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};
$tenantA=new TenantContext('30000000-0000-4000-8000-000000000001','isolation-alpha','tenant-marketing-delivery-test');$tenantB=new TenantContext('30000000-0000-4000-8000-000000000002','isolation-beta','tenant-marketing-delivery-test');
$application=new Application();$application->setupSystemUser();$container=$application->getContainer();$entityManager=$container->getByClass(EntityManager::class);$store=$container->getByClass(TenantContextStore::class);$factory=$container->getByClass(InjectableFactory::class);$transactions=$entityManager->getTransactionManager();$transactions->start();
try{
    $settingsA=$store->runWith($tenantA,fn():array=>$factory->create(MarketingDeliveryService::class)->saveProvider((object)['apiBaseUrl'=>'https://postal.example.test','serverName'=>'Tenant A marketing','apiKey'=>'acceptance-test-key','monthlySendLimit'=>20000]));
    $token=(string)($settingsA['callbackToken']??'');
    $settingsA=$store->runWith($tenantA,fn():array=>$factory->create(MarketingDeliveryService::class)->saveDomain((object)['domainName'=>'mail.example.test','spfInclude'=>'spf.postal.example.test','dkimHost'=>'postal._domainkey.mail.example.test','dkimValue'=>'v=DKIM1; p=acceptance','returnPathHost'=>'psrp.mail.example.test','returnPathValue'=>'rp.postal.example.test']));
    $settingsB=$store->runWith($tenantB,fn():array=>$factory->create(MarketingDeliveryService::class)->getSettings());
    $assert($settingsA['provider']['apiBaseUrl']==='https://postal.example.test'&&$settingsA['provider']['hasCredential']===true,'Tenant A provider was not stored with an encrypted credential.');
    $assert(count($settingsA['domains'])===1&&$settingsA['domains'][0]['domainName']==='mail.example.test','Tenant A sending domain was not stored.');
    $assert($settingsA['readiness']['ready']===false,'Delivery became ready before DNS verification.');
    $assert($settingsB['provider']===null&&count($settingsB['domains'])===0,'Tenant B can see Tenant A delivery configuration.');
    $pdo=$entityManager->getPDO();$secret=$pdo->prepare("SELECT ciphertext FROM nexa_managed_secret WHERE tenant_id=? AND service_id=? AND purpose='marketing-delivery' AND secret_name='postal-api-key'");$secret->execute([$tenantA->tenantId,$tenantA->serviceId]);$ciphertext=(string)$secret->fetchColumn();$assert($ciphertext!==''&&!str_contains($ciphertext,'acceptance-test-key'),'Postal API key was not encrypted at rest.');
    $assert((bool)preg_match('/^[a-f0-9]{48}$/',$token),'A one-time callback token was not issued.');$payload=(object)['event'=>'MessageBounced','timestamp'=>1728060000,'original_message'=>(object)['message_id'=>'postal-test-message','token'=>'postal-test-token','to'=>'bounce@example.test']];
    $first=$factory->create(PostalCallbackService::class)->receive($token,$payload);$second=$factory->create(PostalCallbackService::class)->receive($token,$payload);$assert($first['duplicate']===false&&$second['duplicate']===true,'Postal callback idempotency failed.');
    $suppression=$pdo->prepare("SELECT reason FROM nexa_marketing_suppression WHERE tenant_id=? AND service_id=? AND email_address='bounce@example.test'");$suppression->execute([$tenantA->tenantId,$tenantA->serviceId]);$assert($suppression->fetchColumn()==='hard_bounce','A hard bounce did not create a tenant suppression.');
    $profileId='90000000-0000-4000-8000-000000000063';
    $pdo->prepare('INSERT INTO nexa_marketing_email_profile (id,tenant_id,service_id,mass_email_id) VALUES (?,?,?,?)')->execute([$profileId,$tenantA->tenantId,$tenantA->serviceId,'callback-test']);
    $pdo->prepare('INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,provider_message_id,provider_token,recipient,status) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute(['90000000-0000-4000-8000-000000000064',$tenantA->tenantId,$tenantA->serviceId,$profileId,'callback-test','postal','nested-postal-message','nested-token','nested@example.test','submitted']);
    $nested=(object)['event'=>'MessageSent','payload'=>(object)['status'=>'Sent','timestamp'=>1728060010,'message'=>(object)['message_id'=>'nested-postal-message','token'=>'nested-token','to'=>'nested@example.test']]];
    $nestedResult=$factory->create(PostalCallbackService::class)->receive($token,$nested);
    $assert($nestedResult['duplicate']===false,'Postal webhook envelope was rejected.');
    $event=$pdo->prepare("SELECT event_type,recipient FROM nexa_marketing_delivery_event WHERE tenant_id=? AND service_id=? AND provider_message_id='nested-postal-message'");
    $event->execute([$tenantA->tenantId,$tenantA->serviceId]);$nestedEvent=$event->fetch(PDO::FETCH_ASSOC);
    $assert($nestedEvent['event_type']==='delivered'&&$nestedEvent['recipient']==='nested@example.test','Postal webhook payload was not unwrapped.');
    $status=$pdo->prepare('SELECT status FROM nexa_marketing_delivery_message WHERE id=?');
    foreach(['MessageLoaded','MessageLinkClicked','MessageDelayed','MessageSent'] as $index=>$eventType){
        $nested->event=$eventType;$nested->payload->timestamp=1728060020+$index;
        $factory->create(PostalCallbackService::class)->receive($token,$nested);
        $status->execute(['90000000-0000-4000-8000-000000000064']);
        $assert($status->fetchColumn()==='delivered','An engagement or late delivery event regressed terminal delivery status.');
    }
    $usage=$pdo->prepare("SELECT delivered_count FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month='2024-10-01'");
    $usage->execute([$tenantA->tenantId,$tenantA->serviceId]);
    $assert((int)$usage->fetchColumn()===1,'Repeated or unmatched callbacks inflated delivery usage.');
    echo "Tenant marketing delivery governance tests passed.\n";
}finally{$transactions->rollback();}
