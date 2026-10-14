<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Api\RequestWrapper;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService;
use Espo\Custom\Tools\MarketingEmail\MarketingUnsubscribeService;
use Espo\Custom\Tools\MarketingEmail\MarketingTimelineService;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
use Espo\Custom\Tools\MarketingEmail\PostalCallbackService;
use Espo\Custom\Tools\MarketingEmail\Api\MarketingUnsubscribe;
use Espo\Custom\Tools\Account\AccountTimelineService;
use Slim\Psr7\Factory\ServerRequestFactory;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$app = new Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'marketing-lifecycle-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'marketing-lifecycle-test');
$tx = $em->getTransactionManager();
$tx->start();
try {
    $em->getPDO()->prepare("INSERT INTO nexa_tenant_service (tenant_id,service_id,status) SELECT ?,id,'active' FROM nexa_service_definition WHERE service_key='marketing.contacts' ON DUPLICATE KEY UPDATE status='active'")->execute([$tenantA->tenantId]);
    $fixtures = $store->runWith($tenantA, function () use ($em): array {
        $account = $em->getNewEntity('Account');
        $account->set('name', 'Marketing lifecycle account');
        $em->saveEntity($account);
        $contact = $em->getNewEntity('Contact');
        $contact->set(['firstName' => 'Lifecycle', 'lastName' => 'Recipient', 'accountId' => $account->getId(), 'emailAddress' => 'lifecycle@example.test']);
        $em->saveEntity($contact);
        $campaign = $em->getNewEntity('Campaign');
        $campaign->set(['name' => 'Lifecycle campaign', 'type' => 'Email']);
        $em->saveEntity($campaign);
        $template = $em->getNewEntity('EmailTemplate');
        $template->set(['name'=>'Lifecycle template','subject'=>'Lifecycle test','body'=>'<p>Lifecycle email</p>','isHtml'=>true]);
        $em->saveEntity($template);
        $mass = $em->getNewEntity('MassEmail');
        $mass->set(['name' => 'Lifecycle email', 'emailTemplateId'=>$template->getId(), 'campaignId' => $campaign->getId(), 'optOutEntirely' => true]);
        $em->saveEntity($mass);
        $queue = $em->getNewEntity('EmailQueueItem');
        $queue->set(['massEmailId' => $mass->getId(), 'targetId' => $contact->getId(), 'targetType' => 'Contact', 'status' => 'Sent']);
        $em->saveEntity($queue);
        return ['account' => $account, 'contact' => $contact, 'mass' => $mass, 'queue' => $queue];
    });
    $pdo = $em->getPDO();
    $profileId = '90000000-0000-4000-8000-000000000065';
    $pdo->prepare('INSERT INTO nexa_marketing_email_profile (id,tenant_id,service_id,mass_email_id) VALUES (?,?,?,?)')
        ->execute([$profileId, $tenantA->tenantId, $tenantA->serviceId, $fixtures['mass']->getId()]);
    $pdo->prepare("INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,provider_message_id,provider_token,recipient,status) VALUES (UUID(),?,?,?,?, 'postal','lifecycle-message','lifecycle-token','lifecycle@example.test','submitted')")
        ->execute([$tenantA->tenantId, $tenantA->serviceId, $profileId, $fixtures['queue']->getId()]);
    $settings = $store->runWith($tenantA, fn () => $factory->create(MarketingDeliveryService::class)->saveProvider((object) [
        'apiBaseUrl' => 'https://postal.example.test', 'apiKey' => 'lifecycle-test-key', 'monthlySendLimit' => 100,
    ]));
    $payload = (object) ['event' => 'MessageSent', 'uuid' => 'lifecycle-delivered', 'payload' => (object) [
        'timestamp' => time(), 'message' => (object) ['message_id' => 'lifecycle-message', 'token' => 'lifecycle-token', 'to' => 'lifecycle@example.test'],
    ]];
    $callbacks = $factory->create(PostalCallbackService::class);
    $pdo->prepare('UPDATE nexa_marketing_delivery_message SET provider_message_id=NULL,provider_token=NULL WHERE profile_id=? AND tenant_id=? AND service_id=?')->execute([$profileId,$tenantA->tenantId,$tenantA->serviceId]);
    $callbacks->receive($settings['callbackToken'], $payload);
    $pending = $pdo->prepare("SELECT COUNT(*) FROM nexa_marketing_delivery_event WHERE tenant_id=? AND service_id=? AND profile_id IS NULL AND provider_message_id='lifecycle-message'");
    $pending->execute([$tenantA->tenantId,$tenantA->serviceId]);
    $assert((int)$pending->fetchColumn()===1,'An early callback was not retained for reconciliation.');
    $pdo->prepare("UPDATE nexa_marketing_delivery_message SET provider_message_id='lifecycle-message',provider_token='lifecycle-token' WHERE profile_id=? AND tenant_id=? AND service_id=?")->execute([$profileId,$tenantA->tenantId,$tenantA->serviceId]);
    $store->runWith($tenantB,fn()=>$callbacks->reconcilePending());
    $pending->execute([$tenantA->tenantId,$tenantA->serviceId]);
    $assert((int)$pending->fetchColumn()===1,'Tenant B reconciled Tenant A callback.');
    $store->runWith($tenantA,fn()=>$callbacks->reconcilePending());
    $store->runWith($tenantA,fn()=>$callbacks->reconcilePending());
    $callbacks->receive($settings['callbackToken'], $payload);
    $query = $pdo->prepare("SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND account_id=? AND event_type='email.delivered'");
    $query->execute([$tenantA->tenantId, $tenantA->serviceId, $fixtures['contact']->getId(), $fixtures['account']->getId()]);
    $assert((int) $query->fetchColumn() === 1, 'Delivery timeline projection is missing or duplicated.');
    $eventQuery = $pdo->prepare("SELECT id FROM nexa_marketing_delivery_event WHERE tenant_id=? AND service_id=? AND profile_id=? AND event_type='delivered'");
    $eventQuery->execute([$tenantA->tenantId, $tenantA->serviceId, $profileId]);
    $eventId = (string) $eventQuery->fetchColumn();
    $store->runWith($tenantB, fn () => $factory->create(MarketingTimelineService::class)->project($eventId));
    $other = $pdo->prepare('SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND source_entity_id=?');
    $other->execute([$tenantB->tenantId, $eventId]);
    $assert((int) $other->fetchColumn() === 0, 'A delivery event crossed tenant boundaries.');
    $outbox = $pdo->prepare("SELECT COUNT(*) FROM nexa_outbox_event WHERE tenant_id=? AND service_id=? AND aggregate_id=? AND event_type='email.delivered'");
    $outbox->execute([$tenantA->tenantId, $tenantA->serviceId, $eventId]);
    $assert((int) $outbox->fetchColumn() === 1, 'The delivery event did not publish once to the outbox.');
    $payload->event='MessageLoaded';$payload->uuid='lifecycle-opened';
    $payload->payload->message->message_id='rfc-message-id@example.test';
    $callbacks->receive($settings['callbackToken'], $payload);
    $opened=$pdo->prepare("SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type='email.opened'");
    $opened->execute([$tenantA->tenantId,$tenantA->serviceId,$fixtures['contact']->getId()]);
    $assert((int)$opened->fetchColumn()===1,'A token-matched callback with a different provider ID lost its timeline projection.');

    $unsubscribe = $factory->create(MarketingUnsubscribeService::class);
    $id = $fixtures['queue']->getId();
    $token = $unsubscribe->token($id);
    $rejected = false;
    try { $unsubscribe->inspect($id, str_repeat('0', 64)); } catch (Forbidden) { $rejected = true; }
    $assert($rejected, 'An invalid signature was accepted.');
    $action = $factory->create(MarketingUnsubscribe::class);
    $request = static fn (string $method) => new RequestWrapper(
        (new ServerRequestFactory())->createServerRequest($method, '/api/v1/Nexa/public/marketing-unsubscribe/' . $id . '/' . $token),
        '', ['id' => $id, 'token' => $token]
    );
    $confirmation = $action->process($request('GET'));
    $assert(str_contains((string) $confirmation->getBody(), '<form method="post">'), 'The public link did not render a confirmation form.');
    $suppressed = $pdo->prepare("SELECT COUNT(*) FROM nexa_marketing_suppression WHERE tenant_id=? AND service_id=? AND email_address='lifecycle@example.test' AND is_active=1");
    $suppressed->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $suppressed->fetchColumn() === 0, 'Opening the confirmation page changed preferences.');
    $result = $store->runWith($tenantB, fn () => $action->process($request('POST')));
    $assert(str_contains((string) $result->getBody(), 'You have unsubscribed'), 'The confirmation action did not report success.');
    $unsubscribe->unsubscribe($id, $token);
    $suppressed->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $suppressed->fetchColumn() === 1, 'Unsubscribe failed to suppress future marketing sends.');
    $query = $pdo->prepare("SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type='email.unsubscribed'");
    $query->execute([$tenantA->tenantId, $tenantA->serviceId, $fixtures['contact']->getId()]);
    $assert((int) $query->fetchColumn() === 1, 'Repeated unsubscribe duplicated the customer timeline.');
    $accountPage = $store->runWith($tenantA, fn () => $factory->create(AccountTimelineService::class)->getPage($fixtures['account']->getId(), 'activity', 0, 25));
    $marketingItems = array_values(array_filter($accountPage['list'], static fn ($item) => $item->_entityType === 'MarketingDeliveryEvent'));
    $assert(count($marketingItems) === 3, 'The Account activity feed did not expose the governed delivery and unsubscribe events.');
    $nativeOptOut = $pdo->prepare("SELECT ea.opt_out FROM email_address ea INNER JOIN entity_email_address link ON link.email_address_id=ea.id AND link.tenant_id=ea.tenant_id AND link.service_id=ea.service_id WHERE link.entity_id=? AND link.entity_type='Contact' AND ea.tenant_id=? AND ea.service_id=? AND link.deleted=0 LIMIT 1");
    $nativeOptOut->execute([$fixtures['contact']->getId(), $tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $nativeOptOut->fetchColumn() === 1, 'Native email opt-out was not preserved.');
    $suppressed->execute([$tenantB->tenantId, $tenantB->serviceId]);
    $assert((int) $suppressed->fetchColumn() === 0, 'Unsubscribe changed another tenant.');
    $workspace=$store->runWith($tenantA,fn()=>$factory->create(MarketingEmailWorkspaceService::class)->getWorkspace());
    $reported=array_values(array_filter($workspace['emails'],fn($row)=>$row['id']===$profileId))[0]['deliveryMetrics'];
    $assert($reported['delivered']===1&&$reported['opened']===1&&$reported['unsubscribed']===1,'Delivery results do not reconcile with stored messages and unique recipient events.');
    $assert($workspace['usage']['limit']===100&&$workspace['usage']['remaining']===100&&$workspace['usage']['delivered']===1,'Monthly usage did not reconcile with the tenant ledger.');
    $otherUsage=$store->runWith($tenantB,fn()=>$factory->create(MarketingDeliveryService::class)->usage());
    $assert($otherUsage['delivered']===0,'Tenant B can see Tenant A delivery usage.');
    echo "Tenant marketing lifecycle tests passed.\n";
} finally {
    $tx->rollback();
}
