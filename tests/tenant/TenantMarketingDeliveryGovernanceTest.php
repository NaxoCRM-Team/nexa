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
    $pdo->prepare("INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,provider_message_id,recipient,status,usage_class,reserved_usage_month) VALUES (?,?,?,?,?,'postal','transactional-month-message','month@example.test','submitted','transactional','2024-09-01')")
        ->execute(['90000000-0000-4000-8000-000000000066',$tenantA->tenantId,$tenantA->serviceId,$profileId,'txn-month-queue']);
    $monthPayload=(object)['event'=>'MessageSent','timestamp'=>1728060100,'message'=>(object)['message_id'=>'transactional-month-message','to'=>'month@example.test']];
    $pdo->prepare("INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,provider_token,recipient,status) VALUES (?,?,?,?,?,'postal','','unmatched@example.test','submitted')")
        ->execute(['90000000-0000-4000-8000-000000000067',$tenantA->tenantId,$tenantA->serviceId,$profileId,'unmatched-empty-token']);
    $factory->create(PostalCallbackService::class)->receive($token,$monthPayload);
    $factory->create(PostalCallbackService::class)->receive($token,$monthPayload);
    $status->execute(['90000000-0000-4000-8000-000000000067']);
    $assert($status->fetchColumn()==='submitted','An empty provider token must not let callbacks update another message.');
    foreach(['MessageBounced','MessageComplaint'] as $index=>$type){
        $monthPayload->event=$type;$monthPayload->timestamp=1728060200+$index;
        $factory->create(PostalCallbackService::class)->receive($token,$monthPayload);
    }
    $monthUsage=$pdo->prepare("SELECT delivered_count,failed_count,transactional_delivered_count,transactional_failed_count FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month='2024-09-01'");
    $monthUsage->execute([$tenantA->tenantId,$tenantA->serviceId]);$monthCounts=$monthUsage->fetch(PDO::FETCH_ASSOC);
    $assert((int)$monthCounts['transactional_delivered_count']===1&&(int)$monthCounts['transactional_failed_count']===1,'Late and repeated callbacks must count each transactional outcome once in its reservation month.');
    $assert((int)$monthCounts['delivered_count']===0&&(int)$monthCounts['failed_count']===0,'Transactional callbacks must not alter marketing counters.');

    $transactionalServiceId='90000000-0000-4000-8000-000000000065';
    $pdo->prepare("INSERT INTO nexa_service_definition (id,service_key,display_name,description,measurement_unit) VALUES (?,'email.transactional','Transactional email','Monthly transactional message allowance','messages') ON DUPLICATE KEY UPDATE service_key=VALUES(service_key)")->execute([$transactionalServiceId]);
    $transactionalServiceId=(string)$pdo->query("SELECT id FROM nexa_service_definition WHERE service_key='email.transactional'")->fetchColumn();
    $pdo->prepare("INSERT INTO nexa_plan_service (plan_id,service_id,is_enabled,hard_limit) SELECT DISTINCT plan_id,?,1,800 FROM nexa_tenant_subscription WHERE tenant_id=? ON DUPLICATE KEY UPDATE is_enabled=1,hard_limit=800")->execute([$transactionalServiceId,$tenantA->tenantId]);
    $pdo->prepare("INSERT INTO nexa_tenant_service (tenant_id,service_id,status,hard_limit_override) VALUES (?,?,'active',1200) ON DUPLICATE KEY UPDATE status='active',hard_limit_override=1200,starts_at=NULL,ends_at=NULL")->execute([$tenantA->tenantId,$transactionalServiceId]);
    $limit=\Espo\Custom\Tools\MarketingEmail\MarketingAllowance::transactionalLimit($pdo,$tenantA,0);
    $assert($limit===1200,'Transactional allowance must use a direct message limit, not the 20x marketing multiplier.');
    $assert(\Espo\Custom\Tools\MarketingEmail\MarketingAllowance::transactionalLimit($pdo,$tenantA,500)===500,'Provider ceiling must still apply to transactional entitlement.');
    $pdo->prepare('DELETE FROM nexa_tenant_service WHERE tenant_id=? AND service_id=?')->execute([$tenantB->tenantId,$transactionalServiceId]);
    $assert(\Espo\Custom\Tools\MarketingEmail\MarketingAllowance::transactionalLimit($pdo,$tenantB,0)===0,'Another tenant must not inherit a transactional entitlement.');
    $policy=$store->runWith($tenantA,fn():array=>$factory->create(MarketingDeliveryService::class)->getSettings())['transactionalPolicy'];
    $assert($policy['monthlyLimit']===1200&&!$policy['deliveryEnabled'],'Allowance must not falsely advertise implemented transactional delivery.');
    $consentWorkspace=$store->runWith($tenantA,fn():array=>$factory->create(\Espo\Custom\Tools\Consent\ConsentService::class)->getWorkspace());
    $customerPurposes=array_values(array_filter($consentWorkspace['purposes'],static fn(array $purpose):bool=>$purpose['purposeKey']==='customer_service'));
    $policy=$store->runWith($tenantA,fn():array=>$factory->create(MarketingDeliveryService::class)->getSettings())['transactionalPolicy'];
    $assert($policy['purpose']['id']===$customerPurposes[0]['id'],'Transactional settings must reuse the tenant-owned native consent purpose.');
    $purposeId=$policy['purpose']['id'];
    foreach([['is_active',0],['channels_json','["phone"]'],['default_legal_basis','NotApplicable']] as [$column,$value]){
        $pdo->prepare("UPDATE nexa_consent_purpose SET is_active=1,channels_json='[\"email\"]',default_legal_basis='PerformanceOfContract' WHERE id=? AND tenant_id=? AND service_id=?")->execute([$purposeId,$tenantA->tenantId,$tenantA->serviceId]);
        $pdo->prepare("UPDATE nexa_consent_purpose SET `$column`=? WHERE id=? AND tenant_id=? AND service_id=?")->execute([$value,$purposeId,$tenantA->tenantId,$tenantA->serviceId]);
        $policy=$store->runWith($tenantA,fn():array=>$factory->create(MarketingDeliveryService::class)->getSettings())['transactionalPolicy'];
        $assert($policy['purpose']===null,'Inactive or unsupported operational purposes must not qualify for transactional configuration.');
    }
    foreach([['status','disabled'],['ends_at','2000-01-01 00:00:00'],['starts_at','2100-01-01 00:00:00'],['hard_limit_override',0]] as [$column,$value]){
        $pdo->prepare("UPDATE nexa_tenant_service SET status='active',starts_at=NULL,ends_at=NULL,hard_limit_override=1200 WHERE tenant_id=? AND service_id=?")->execute([$tenantA->tenantId,$transactionalServiceId]);
        $pdo->prepare("UPDATE nexa_tenant_service SET `$column`=? WHERE tenant_id=? AND service_id=?")->execute([$value,$tenantA->tenantId,$transactionalServiceId]);
        $assert(\Espo\Custom\Tools\MarketingEmail\MarketingAllowance::transactionalLimit($pdo,$tenantA,0)===0,'Unavailable transactional entitlement must fail closed.');
    }
    echo "Tenant marketing delivery governance and separate transactional entitlement tests passed.\n";
}finally{$transactions->rollback();}
