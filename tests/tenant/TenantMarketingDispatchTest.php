<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$postalCapture = defined('NEXA_POSTAL_CAPTURE_ACCEPTANCE') && NEXA_POSTAL_CAPTURE_ACCEPTANCE === true;
$postalSettings = $postalCapture ? json_decode(file_get_contents($root . '/.local/postal/config/local-credentials.json'), true, 512, JSON_THROW_ON_ERROR) : null;
if ($postalCapture && ($postalSettings['apiBaseUrl'] !== 'https://localhost:8443' || $postalSettings['sender'] !== 'newsletter@nexa.local.test')) {
    throw new RuntimeException('Only the isolated Postal capture fixture is allowed.');
}
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Campaign\CampaignWorkspaceService;
use Espo\Custom\Tools\Consent\ConsentService;
use Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-marketing-dispatch-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-marketing-dispatch-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
if ($postalCapture) {
    $container->getByClass(\Espo\Core\Utils\Config::class)->set('nexaPostalLocalCaFile', $root . '/.local/postal/root.crt');
}
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$services = $container->getByClass(ServiceContainer::class);
$pdo = $entityManager->getPDO();
$transactions = $entityManager->getTransactionManager();
$transactions->start();

try {
    if ($postalCapture) {
        // Isolate worker execution from pre-existing schedules, within this rollback-only fixture.
        $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='paused' WHERE tenant_id=? AND service_id=? AND lifecycle_status IN ('scheduled','sending')")->execute([$tenantA->tenantId, $tenantA->serviceId]);
    }
    $pdo->prepare("INSERT INTO nexa_tenant_service (tenant_id,service_id,status) SELECT ?,id,'active' FROM nexa_service_definition WHERE service_key='marketing.contacts' ON DUPLICATE KEY UPDATE status='active'")->execute([$tenantA->tenantId]);
    $allowance = \Espo\Custom\Tools\MarketingEmail\MarketingAllowance::class;
    $pdo->prepare("UPDATE nexa_tenant_service ts INNER JOIN nexa_service_definition sd ON sd.id=ts.service_id SET ts.hard_limit_override=1000 WHERE ts.tenant_id=? AND sd.service_key='marketing.contacts'")->execute([$tenantA->tenantId]);
    $assert($allowance::limit($pdo, $tenantA, 0) === 20000, 'A 1,000 contact tier must allow 20,000 monthly sends.');
    $assert($allowance::limit($pdo, $tenantA, 100) === 100, 'A lower provider cap must remain enforced.');
    $pdo->prepare("INSERT INTO nexa_tenant_service (tenant_id,service_id,status,hard_limit_override) SELECT ?,id,'active',2000 FROM nexa_service_definition WHERE service_key='marketing.contacts' ON DUPLICATE KEY UPDATE status='active',hard_limit_override=2000")->execute([$tenantB->tenantId]);
    $assert($allowance::limit($pdo, $tenantB, 0) === 40000, 'Another tenant must retain its own contact-tier allowance.');
    $pdo->prepare("UPDATE nexa_tenant_subscription SET status='trialing',trial_ends_at=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE tenant_id=?")->execute([$tenantA->tenantId]);
    $assert($allowance::limit($pdo, $tenantA, 0) === 0, 'Expired trials must block marketing sends.');
    $pdo->prepare("UPDATE nexa_tenant_subscription SET status='active' WHERE tenant_id=?")->execute([$tenantA->tenantId]);
    $pdo->prepare("UPDATE nexa_tenant_service ts INNER JOIN nexa_service_definition sd ON sd.id=ts.service_id SET ts.status='disabled' WHERE ts.tenant_id=? AND sd.service_key='marketing.contacts'")->execute([$tenantA->tenantId]);
    $assert($allowance::limit($pdo, $tenantA, 0) === 0, 'Disabled marketing entitlements must block sending.');
    $pdo->prepare("UPDATE nexa_tenant_service ts INNER JOIN nexa_service_definition sd ON sd.id=ts.service_id SET ts.status='active' WHERE ts.tenant_id=? AND sd.service_key='marketing.contacts'")->execute([$tenantA->tenantId]);
    $contact = $store->runWith($tenantA, fn () => $services->get('Contact')->create((object) [
        'firstName' => 'Governed', 'lastName' => 'Recipient',
        'emailAddress' => 'governed-recipient@example.test',
        'addressCountry' => 'Dispatch Test', 'marketingStatus' => 'Marketing',
    ], CreateParams::create()));
    $pdo->prepare("UPDATE contact SET marketing_status='Marketing',do_not_contact=0 WHERE id=? AND tenant_id=? AND service_id=?")
        ->execute([$contact->getId(), $tenantA->tenantId, $tenantA->serviceId]);

    $consent = $store->runWith($tenantA, fn (): array => $factory->create(ConsentService::class)->getWorkspace());
    $purpose = array_values(array_filter($consent['purposes'], static fn (array $item): bool => $item['purposeKey'] === 'marketing_communications'))[0];
    $purposeId = (string) $purpose['id'];
    $store->runWith($tenantA, fn (): array => $factory->create(ConsentService::class)->recordDecision((object) [
        'contactId' => $contact->getId(), 'purposeId' => $purposeId, 'channel' => 'email',
        'status' => 'granted', 'source' => 'manual', 'legalBasis' => 'FreelyGivenConsent',
        'evidenceNote' => 'Governed dispatcher tenant acceptance test.',
    ]));
    $segment = $store->runWith($tenantA, fn (): array => $factory->create(SegmentWorkspaceService::class)->save((object) [
        'name' => 'Dispatcher acceptance audience', 'type' => 'dynamic', 'matchMode' => 'all',
        'rules' => [(object) ['field' => 'country', 'operator' => 'equals', 'value' => 'Dispatch Test']],
    ]));
    $campaign = $store->runWith($tenantA, fn (): array => $factory->create(CampaignWorkspaceService::class)->save((object) [
        'name' => 'Dispatcher acceptance campaign', 'type' => 'Email', 'channel' => 'email',
        'purposeId' => $purposeId, 'audienceIds' => [$segment['id']], 'exclusionIds' => [], 'enrollmentMode' => 'snapshot',
    ]));
    $enrollment = $store->runWith($tenantA, fn (): array => $factory->create(CampaignWorkspaceService::class)->enroll((string) $campaign['id']));
    $assert($enrollment['eligibleCount'] === 1, 'The dispatcher fixture did not produce one eligible recipient.');

    $draft = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->save((object) [
        'name' => 'Governed dispatcher email', 'campaignId' => $campaign['id'], 'purposeId' => $purposeId,
        'subject' => $postalCapture ? 'Nexa governed Postal ' . bin2hex(random_bytes(8)) : 'Governed delivery test',
        'fromAddress' => $postalCapture ? $postalSettings['sender'] : 'news@example.test', 'fromName' => 'Example team',
        'bodyHtml' => '<p>Hello {Contact.firstName},</p><p>Dispatcher test.</p>',
        'startAt' => gmdate('Y-m-d H:i:s', time() + 3600),
    ]));
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'submit']));
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'approve']));

    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveProvider((object) [
        'apiBaseUrl' => $postalCapture ? $postalSettings['apiBaseUrl'] : 'https://postal.example.test', 'serverName' => 'Dispatch test',
        'apiKey' => $postalCapture ? $postalSettings['apiKey'] : 'dispatch-acceptance-key', 'monthlySendLimit' => 100,
    ]));
    $domainName = $postalCapture ? 'nexa.local.test' : 'example.test';
    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveDomain((object) ['domainName' => $domainName]));
    $domainId = (string) array_values(array_filter($settings['domains'], static fn (array $domain): bool => $domain['domainName'] === $domainName))[0]['id'];
    $pdo->prepare("UPDATE nexa_marketing_sending_domain SET ownership_status='verified',spf_status='verified',dkim_status='verified',dmarc_status='verified',return_path_status='verified',verified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")
        ->execute([$domainId, $tenantA->tenantId, $tenantA->serviceId]);

    $scheduled = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDispatchService::class)->schedule((string) $draft['id']));
    $assert($scheduled['status'] === 'scheduled' && $scheduled['eligibleCount'] === 1, 'The approved email was not scheduled with its eligible audience.');
    $profile = $pdo->prepare('SELECT lifecycle_status FROM nexa_marketing_email_profile WHERE id=? AND tenant_id=? AND service_id=?');
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'scheduled', 'The governed profile did not enter scheduled state.');
    $native = $pdo->prepare('SELECT status FROM mass_email WHERE id=? AND tenant_id=? AND service_id=?');
    $native->execute([$draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($native->fetchColumn() === 'Nexa Scheduled', 'The native MassEmail was not reserved away from the SMTP processor.');
    $paused = $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'pause'));
    $assert($paused['status'] === 'paused', 'Scheduled delivery must be pausable.');
    $native->execute([$draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($native->fetchColumn() === 'Nexa Paused', 'Native delivery must remain held while paused.');
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'paused', 'A worker must not advance a paused profile.');
    $blocked = false;
    try { $store->runWith($tenantB, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'resume')); } catch (NotFound) { $blocked = true; }
    $assert($blocked, 'Another tenant must not resume this email.');
    $resumed = $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'resume'));
    $assert($resumed['status'] === 'scheduled', 'A paused schedule must resume without recreating its queue.');

    if ($postalCapture) {
        $usage = $pdo->prepare("SELECT COALESCE(SUM(accepted_count),0) FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
        $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $usageBefore = (int) $usage->fetchColumn();
        $pdo->prepare('UPDATE mass_email SET start_at=? WHERE id=? AND tenant_id=? AND service_id=?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 60), $draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
        $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
        $receipt = $pdo->prepare('SELECT status,provider_message_id,last_error FROM nexa_marketing_delivery_message WHERE profile_id=? AND tenant_id=? AND service_id=?');
        $receipt->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $message = $receipt->fetch(PDO::FETCH_ASSOC);
        $assert(is_array($message) && $message['status'] === 'submitted' && $message['provider_message_id'] !== '', 'The governed queue did not receive Postal acceptance.');
        $subjectQuery = $pdo->prepare('SELECT subject FROM email_template WHERE id=(SELECT email_template_id FROM mass_email WHERE id=?)');
        $subjectQuery->execute([$draft['massEmailId']]);
        $subject = $subjectQuery->fetchColumn();
        $captured = false;
        for ($attempt = 0; $attempt < 60; $attempt++) {
            $messages = json_decode(file_get_contents('http://localhost:8026/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($messages['messages'] ?? [] as $mail) {
                if (($mail['Subject'] ?? '') !== $subject) { continue; }
                $detail = json_decode(file_get_contents('http://localhost:8026/api/v1/message/' . rawurlencode($mail['ID'])), true, 512, JSON_THROW_ON_ERROR);
                $assert(str_contains($detail['HTML'] ?? '', 'Hello Governed'), 'Native Contact personalization was not rendered.');
                $assert(str_contains($detail['HTML'] ?? '', 'unsubscribe'), 'The governed unsubscribe link is missing.');
                $captured = true;
            }
            if ($captured) { break; }
            sleep(2);
        }
        $assert($captured, 'Postal did not deliver the governed campaign to SMTP capture.');
        if (defined('NEXA_POSTAL_CALLBACK_ACCEPTANCE') && NEXA_POSTAL_CALLBACK_ACCEPTANCE === true) {
            $event = null;
            for ($attempt = 0; $attempt < 60; $attempt++) {
                $lines = @file($root . '/.local/postal/config/captured-events.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $candidate = json_decode($line, false, 512, JSON_THROW_ON_ERROR);
                    if (($candidate->event ?? '') === 'MessageSent' && ($candidate->payload->message->subject ?? '') === $subject) {
                        $event = $candidate;
                    }
                }
                if ($event !== null) { break; }
                sleep(2);
            }
            $assert($event !== null, 'Postal did not deliver its actual MessageSent webhook.');
            $callbackSettings = $store->runWith($tenantA, fn () => $factory->create(MarketingDeliveryService::class)->rotateCallbackToken());
            $callback = $factory->create(\Espo\Custom\Tools\MarketingEmail\PostalCallbackService::class);
            $result = $callback->receive($callbackSettings['callbackToken'], $event);
            $assert($result['accepted'] && !$result['duplicate'], 'The real Postal webhook was not accepted.');
            $receipt->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
            $assert($receipt->fetch(PDO::FETCH_ASSOC)['status'] === 'delivered', 'The real Postal event did not reconcile delivery.');
            $assert($callback->receive($callbackSettings['callbackToken'], $event)['duplicate'], 'A real webhook retry must be idempotent.');
            $count = $pdo->prepare("SELECT COUNT(*) FROM nexa_timeline_event WHERE tenant_id=? AND service_id=? AND contact_id=? AND event_type='email.delivered'");
            $count->execute([$tenantA->tenantId, $tenantA->serviceId, $contact->getId()]);
            $assert((int) $count->fetchColumn() === 1, 'A real webhook must project exactly one Contact delivery activity.');
            $count = $pdo->prepare("SELECT COUNT(*) FROM nexa_outbox_event o INNER JOIN nexa_marketing_delivery_event e ON e.id=o.aggregate_id WHERE e.profile_id=? AND o.tenant_id=? AND o.service_id=? AND o.event_type='email.delivered'");
            $count->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
            $assert((int) $count->fetchColumn() === 1, 'A repeated real webhook must not duplicate downstream delivery events.');
            $blocked = false;
            try { $callback->receive(str_repeat('0', 48), $event); } catch (\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
            $assert($blocked, 'A real Postal payload must not bypass callback authentication.');
            echo "Actual Postal HTTP webhook capture and authenticated reconciliation passed.\n";
        }
        $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $usage->fetchColumn() === $usageBefore + 1, 'A real submission must consume exactly one monthly send.');
        $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
        $receipt->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert(count($receipt->fetchAll()) === 1, 'A repeated worker created duplicate delivery receipts.');
        $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $usage->fetchColumn() === $usageBefore + 1, 'A repeated worker must not consume another monthly send.');
        $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert($profile->fetchColumn() === 'completed', 'The campaign queue must finish after submission.');
        $blocked = false;
        try { $store->runWith($tenantB, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'pause')); } catch (NotFound) { $blocked = true; }
        $assert($blocked, 'Another tenant must not control the real Postal campaign.');
        echo "Governed campaign, native personalization, unsubscribe and actual Postal SMTP capture passed.\n";
        return;
    }

    // Eligibility is deliberately changed after scheduling. The worker must
    // suppress the recipient at send time and must not contact the provider.
    $pdo->prepare('UPDATE contact SET do_not_contact=1 WHERE id=? AND tenant_id=? AND service_id=?')
        ->execute([$contact->getId(), $tenantA->tenantId, $tenantA->serviceId]);
    $pdo->prepare('UPDATE mass_email SET start_at=? WHERE id=? AND tenant_id=? AND service_id=?')
        ->execute([gmdate('Y-m-d H:i:s', time() - 60), $draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $queue = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE tenant_id=? AND service_id=? AND mass_email_id=? AND deleted=0');
    $queue->execute([$tenantA->tenantId, $tenantA->serviceId, $draft['massEmailId']]);
    $assert((int) $queue->fetchColumn() === 0, 'A recipient that became do-not-contact was added to the delivery queue.');
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'completed', 'A fully suppressed scheduled email did not finish without delivery.');

    $item = $store->runWith($tenantA, function () use ($entityManager, $draft, $contact) {
        $item = $entityManager->getNewEntity('EmailQueueItem');
        $item->set(['massEmailId' => $draft['massEmailId'], 'targetId' => $contact->getId(), 'targetType' => 'Contact', 'status' => 'Sending']);
        $entityManager->saveEntity($item);
        return $item;
    });
    $pdo->prepare("INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,recipient,status) VALUES (?,?,?,?,?,'postal','governed-recipient@example.test','submitting')")
        ->execute(['70000000-0000-4000-8000-000000000001',$tenantA->tenantId,$tenantA->serviceId,$draft['id'],$item->getId()]);
    $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='sending' WHERE id=?")->execute([$draft['id']]);
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'sending', 'An in-flight submission must not be marked complete.');
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'pause'));
    $blocked = false;
    try { $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->control((string) $draft['id'], 'resume')); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
    $assert($blocked, 'Resume must reject unconfirmed in-flight submissions.');
    $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='sending' WHERE id=?")->execute([$draft['id']]);
    $pdo->prepare("UPDATE nexa_marketing_delivery_message SET modified_at=DATE_SUB(NOW(),INTERVAL 20 MINUTE) WHERE email_queue_item_id=?")->execute([$item->getId()]);
    $method = new ReflectionMethod(MarketingDispatchService::class, 'pauseStaleSubmissions');
    $method->invoke($factory->create(MarketingDispatchService::class), $tenantB);
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'sending', 'Another tenant must not pause this submission.');
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'paused', 'Stale uncertain submissions must pause for review, not resend.');
    $audit = $pdo->prepare("SELECT COUNT(*) FROM nexa_marketing_email_event WHERE profile_id=? AND event_type='submission_review_required'");
    $audit->execute([$draft['id']]);
    $assert((int) $audit->fetchColumn() === 1, 'Recovery must emit one review event.');

    $isolated = false;
    try {
        $store->runWith($tenantB, fn (): array => $factory->create(MarketingDispatchService::class)->schedule((string) $draft['id']));
    } catch (NotFound) {
        $isolated = true;
    }
    $assert($isolated, 'Tenant B could schedule Tenant A marketing email.');
    echo "Tenant marketing dispatch tests passed.\n";
} finally {
    $transactions->rollback();
}
