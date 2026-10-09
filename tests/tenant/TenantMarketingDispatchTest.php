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
$attachmentFixtures = [];
$attachmentCleanup = [];
$attachmentGovernance = defined('NEXA_MARKETING_ATTACHMENT_GOVERNANCE_TEST');
$attachmentDelivery = defined('NEXA_MARKETING_ATTACHMENT_DELIVERY_TEST');
$imageGovernance = defined('NEXA_MARKETING_IMAGE_GOVERNANCE_TEST');
$conditionalGovernance = defined('NEXA_MARKETING_CONDITIONAL_GOVERNANCE_TEST');
$singleSendGovernance = defined('NEXA_MARKETING_SINGLE_SEND_TEST');
$reusableSendGovernance = defined('NEXA_MARKETING_REUSABLE_SEND_TEST');
$hostedPublication = null;

try {
    if ($postalCapture || $imageGovernance || $conditionalGovernance || $singleSendGovernance || $reusableSendGovernance) {
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

    if ($attachmentGovernance || $attachmentDelivery) {
        if ($attachmentGovernance) {
            foreach ([
                ['name' => 'bad.exe', 'mimeType' => 'text/plain', 'data' => base64_encode('Not allowed')],
                ['name' => 'bad.txt', 'mimeType' => 'image/png', 'data' => base64_encode('Wrong MIME')],
                ['name' => 'empty.txt', 'mimeType' => 'text/plain', 'data' => ''],
                ['name' => 'bad.txt', 'mimeType' => 'text/plain', 'data' => '%%%invalid%%%'],
                ['name' => 'large.txt', 'mimeType' => 'text/plain', 'data' => str_repeat('A', 4 * (int) ceil(5 * 1024 * 1024 / 3) + 4)],
            ] as $invalidUpload) {
                $blocked = false;
                try { $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->uploadAttachment((object) $invalidUpload)); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
                $assert($blocked, 'Unsafe marketing upload must be rejected before storage.');
            }
            $user = $container->getByClass(\Espo\Entities\User::class);
            $originalType = $user->get('type');
            try {
                $user->set('type', 'regular');
                $blocked = false;
                try { $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->uploadAttachment((object) ['name' => 'blocked.txt', 'mimeType' => 'text/plain', 'data' => base64_encode('Blocked upload')])); } catch (\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
                $assert($blocked, 'Non-administrators must not upload marketing attachments.');
            } finally { $user->set('type', $originalType); }
        }
        foreach ([$tenantA, $tenantB] as $tenant) {
            $file = $store->runWith($tenant, fn () => $factory->create(MarketingEmailWorkspaceService::class)->uploadAttachment((object) ['name' => 'marketing-guide.txt', 'mimeType' => 'text/plain', 'data' => base64_encode("Approved marketing guide.\n")]));
            $attachmentFixtures[] = $store->runWith($tenant, fn () => $entityManager->getEntityById('Attachment', $file['id']));
            $attachmentCleanup[] = ['tenant' => $tenant, 'attachment' => end($attachmentFixtures)];
        }
    }
    if ($imageGovernance) {
        $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $file = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\TenantFile\TenantImageLibrary::class)->uploadFile('dispatch-image.png', 'image/png', $encoded));
        $attachment = $store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $file['id']));
        $attachmentCleanup[] = ['tenant'=>$tenantA,'attachment'=>$attachment];
        $document = $store->runWith($tenantA, fn () => $services->get('Document')->create((object) ['name'=>'Dispatch image','fileId'=>$file['id'],'publishDate'=>gmdate('Y-m-d')], CreateParams::create()));
        $assetId = 'dispatch-' . bin2hex(random_bytes(12));
        $pdo->prepare('INSERT INTO nexa_asset_profile (id,tenant_id,service_id,current_attachment_id,document_id,display_name,access_scope) VALUES (?,?,?,?,?,?,?)')->execute([$assetId,$tenantA->tenantId,$tenantA->serviceId,$file['id'],$document->getId(),'Dispatch image','public']);
        $hostedPublication = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingImageService::class)->publish((object) ['assetId'=>$assetId,'confirmPublicCopy'=>true]));
        $publicationId = substr($hostedPublication['id'], 5);
        $query = $pdo->prepare('SELECT attachment_id FROM nexa_marketing_image_publication WHERE id=?'); $query->execute([$publicationId]);
        $snapshotId = $query->fetchColumn();
        $attachmentCleanup[] = ['tenant'=>$tenantA,'attachment'=>$store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $snapshotId))];
    }
    $conditionalBody = $conditionalGovernance ? $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingConditionalContentService::class)->compose((object) ['field'=>'firstName','operator'=>'equals','value'=>'Governed','matchingText'=>'Conditional welcome {Contact.firstName}','fallbackText'=>'General welcome']))['bodyHtml'] : '';
    if ($conditionalGovernance) $conditionalBody .= $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingConditionalContentService::class)->compose((object) ['field'=>'leadScore','operator'=>'at_least','value'=>'50','matchingText'=>'High score message','fallbackText'=>'Standard score message']))['bodyHtml'];
    $draftInput = (object) [
        'deliveryMode' => $singleSendGovernance ? 'single_send' : 'campaign',
        'name' => 'Governed dispatcher email', 'campaignId' => $campaign['id'], 'purposeId' => $purposeId,
        'subject' => $postalCapture ? 'Nexa governed Postal ' . bin2hex(random_bytes(8)) : 'Governed delivery test',
        'fromAddress' => $postalCapture ? $postalSettings['sender'] : 'news@example.test', 'fromName' => 'Example team',
        'bodyHtml' => '<p>Hello {Contact.firstName},</p><p>Dispatcher test.</p>' . $conditionalBody . ($hostedPublication ? '<img src="' . htmlspecialchars($hostedPublication['url'], ENT_QUOTES) . '">' : ''),
        'preheader' => 'Preview for {Contact.firstName} <offer>',
        'startAt' => gmdate('Y-m-d H:i:s', time() + 3600),
        ...(($attachmentGovernance || $attachmentDelivery) ? ['attachmentsIds' => [$attachmentFixtures[0]->getId()]] : []),
    ];
    if ($conditionalGovernance) {
        $before=(int) $pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn();
        $invalid=clone $draftInput; $invalid->bodyHtml='{{#if firstName}}Unclosed'; $blocked=false;
        try {$store->runWith($tenantA,fn ()=>$factory->create(MarketingEmailWorkspaceService::class)->save($invalid));}
        catch (\Espo\Core\Exceptions\BadRequest) {$blocked=true;}
        $assert($blocked && (int) $pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn()===$before,'Invalid conditions must reject saving without creating a partial email profile.');
    }
    if ($singleSendGovernance) {
        $before = (int) $pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn();
        foreach (['single', '', ['single_send']] as $invalidMode) {
            $invalid = clone $draftInput; $invalid->deliveryMode = $invalidMode;
            $blocked = false;
            try { $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->save($invalid)); }
            catch (\Espo\Core\Exceptions\BadRequest) {$blocked = true;}
            $assert($blocked, 'Invalid delivery modes must not silently become campaign-wide sends.');
        }
        $assert((int) $pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn() === $before, 'Invalid delivery modes must not create partial email drafts.');
    }
    $draft = $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->save($draftInput));
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'submit']));
    if ($conditionalGovernance) {
        $templateQuery=$pdo->prepare('SELECT email_template_id FROM mass_email WHERE id=? AND tenant_id=? AND service_id=?');
        $templateQuery->execute([$draft['massEmailId'],$tenantA->tenantId,$tenantA->serviceId]); $templateId=$templateQuery->fetchColumn();
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['{{#if firstName}}Broken',$templateId,$tenantA->tenantId,$tenantA->serviceId]);
        $blocked=false;
        try {$store->runWith($tenantA,fn ()=>$factory->create(MarketingEmailWorkspaceService::class)->transition($draft['id'],(object) ['action'=>'approve']));}
        catch (\Espo\Core\Exceptions\BadRequest) {$blocked=true;}
        $assert($blocked,'Malformed conditional content in the native template must block approval.');
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=? AND tenant_id=? AND service_id=?')->execute([$draftInput->bodyHtml,$templateId,$tenantA->tenantId,$tenantA->serviceId]);
    }
    if ($attachmentGovernance) {
        $attachmentService = $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingAttachmentService::class);
        $manifest = $store->runWith($tenantA, fn () => $attachmentService->versionManifest($draft['id'], 1));
        $original = $store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $attachmentCleanup[0]['attachment']->getId()));
        $assert($original->get('field') === 'nexaTenantFile' && !$original->get('parentId'), 'Library originals must not be moved into the template.');
        $assert(count($manifest) === 1 && $manifest[0]['sha256'] === hash('sha256', "Approved marketing guide.\n"), 'Saved files must have an immutable version manifest.');
        $attachmentFixtures[0] = $store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $manifest[0]['id']));
        $attachmentCleanup[] = ['tenant' => $tenantA, 'attachment' => $attachmentFixtures[0]];
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $attachmentService->files([$attachmentFixtures[1]->getId()])); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Another tenant file must not be selected.');
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['changed-guide.txt', $attachmentFixtures[0]->getId(), $tenantA->tenantId, $tenantA->serviceId]);
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->transition($draft['id'], (object) ['action' => 'approve'])); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Files changed since saving must not be approved.');
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['marketing-guide.txt', $attachmentFixtures[0]->getId(), $tenantA->tenantId, $tenantA->serviceId]);
    }
    if ($imageGovernance) {
        $savedImages = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingImageService::class)->versionManifest($draft['id'], 1));
        $assert(count($savedImages) === 1, 'Saved image URLs must be included in version evidence.');
        $pdo->prepare("UPDATE nexa_marketing_image_publication SET status='revoked' WHERE id=?")->execute([$publicationId]);
        $blocked = false;
        try {$store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->transition($draft['id'], (object) ['action'=>'approve']));}
        catch (\Espo\Core\Exceptions\BadRequest) {$blocked = true;}
        $assert($blocked, 'A revoked image must block native marketing approval.');
        $pdo->prepare("UPDATE nexa_marketing_image_publication SET status='published' WHERE id=?")->execute([$publicationId]);
    }
    $store->runWith($tenantA, fn (): array => $factory->create(MarketingEmailWorkspaceService::class)->transition((string) $draft['id'], (object) ['action' => 'approve']));
    if ($conditionalGovernance) {
        $history=$pdo->prepare('SELECT body_html FROM nexa_marketing_email_version WHERE profile_id=? AND tenant_id=? AND service_id=? AND version_number=1');
        $history->execute([$draft['id'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert($history->fetchColumn()===$draftInput->bodyHtml,'Immutable native-backed content versions must retain the condition and both branches.');
        $rendered=$store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingPreviewService::class)->preview((object) ['contactId'=>$contact->getId(),'subject'=>$draftInput->subject,'bodyHtml'=>$draftInput->bodyHtml]));
        $assert(str_contains($rendered['bodyHtml'],'Conditional welcome Governed') && !str_contains($rendered['bodyHtml'],'General welcome'),'The approved native-backed template must render only its matching branch.');
        $assert(str_contains($rendered['bodyHtml'],'Standard score message') && !str_contains($rendered['bodyHtml'],'High score message'),'Saved and approved numeric conditions must use the same native recipient renderer.');
        echo "Conditional content save, immutable version and approval governance tests passed.\n";
        return;
    }
    if ($attachmentGovernance) {
        $templateQuery = $pdo->prepare('SELECT email_template_id FROM mass_email WHERE id=?');
        $templateQuery->execute([$draft['massEmailId']]);
        $templateId = $templateQuery->fetchColumn();
        $template = $store->runWith($tenantA, fn () => $entityManager->getEntityById('EmailTemplate', $templateId));
        $files = $store->runWith($tenantA, fn () => $attachmentService->approvedFiles(['id' => $draft['id'], 'version_number' => 1], $template, true));
        $assert(count($files) === 1 && $files[0]->contents === "Approved marketing guide.\n", 'Native template files must match approval checksums.');
        $reusable = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->reusableTemplate($templateId));
        $assert(count($reusable['attachments']) === 1 && $reusable['attachments'][0]['name'] === 'marketing-guide.txt' && !isset($reusable['attachments'][0]['sha256']), 'Reusable templates must expose validated file metadata without private checksums.');
        $blocked = false;
        try { $store->runWith($tenantB, fn () => $factory->create(MarketingEmailWorkspaceService::class)->reusableTemplate($templateId)); } catch (\Espo\Core\Exceptions\NotFound|\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
        $assert($blocked, 'Another tenant must not load reusable template content or files.');
        $assert($template->get('name') === $reusable['name'], 'Staging a template must not modify its source record.');
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['changed-guide.txt', $attachmentFixtures[0]->getId(), $tenantA->tenantId, $tenantA->serviceId]);
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $attachmentService->approvedFiles(['id' => $draft['id'], 'version_number' => 1], $template, true)); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Files changed after approval must fail verification.');
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['marketing-guide.txt', $attachmentFixtures[0]->getId(), $tenantA->tenantId, $tenantA->serviceId]);
        $restored = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->restoreVersion($draft['id'], 1));
        $assert($restored['version'] === 2 && count($store->runWith($tenantA, fn () => $attachmentService->versionManifest($draft['id'], 2))) === 1, 'Restoring a version must preserve its attachments in a fresh draft.');
        foreach ($store->runWith($tenantA, fn () => $attachmentService->versionManifest($draft['id'], 2)) as $file) $attachmentCleanup[] = ['tenant' => $tenantA, 'attachment' => $store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $file['id']))];
        $reusable = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->reusableTemplate($templateId));
        $applied = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->save((object) [
            'name' => 'Reusable attachment draft', 'campaignId' => $campaign['id'], 'purposeId' => $purposeId,
            'subject' => $reusable['subject'], 'bodyHtml' => $reusable['body'], 'fromAddress' => 'news@example.test',
            'attachmentsIds' => array_column($reusable['attachments'], 'id'),
            'startAt' => gmdate('Y-m-d H:i:s', time() + 3600),
        ]));
        $appliedFiles = $store->runWith($tenantA, fn () => $attachmentService->versionManifest($applied['id'], 1));
        foreach ($appliedFiles as $file) $attachmentCleanup[] = ['tenant' => $tenantA, 'attachment' => $store->runWith($tenantA, fn () => $entityManager->getEntityById('Attachment', $file['id']))];
        $assert(count($appliedFiles) === 1 && $appliedFiles[0]['id'] !== $reusable['attachments'][0]['id'] && $appliedFiles[0]['sha256'] === $manifest[0]['sha256'], 'Applying a reusable template must create independent native attachment relationships with the same validated bytes.');
        $sourceAfter = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->reusableTemplate($templateId));
        $assert($sourceAfter === $reusable, 'Applying and saving must not change the reusable source template.');
        echo "Tenant marketing attachment selection, template application, approval and restoration passed.\n";
        return;
    }

    if (defined('NEXA_POSTAL_PLAIN_TEXT_ACCEPTANCE') && NEXA_POSTAL_PLAIN_TEXT_ACCEPTANCE === true) {
        // Exercise the existing native template type, without changing production draft storage.
        $pdo->prepare('UPDATE email_template SET is_html=0,body=? WHERE id=(SELECT email_template_id FROM mass_email WHERE id=?) AND tenant_id=? AND service_id=?')
            ->execute(["Hello {Contact.firstName},\nPlain-text <customer> content.", $draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
    }

    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveProvider((object) [
        'apiBaseUrl' => $postalCapture ? $postalSettings['apiBaseUrl'] : 'https://postal.example.test', 'serverName' => 'Dispatch test',
        'apiKey' => $postalCapture ? $postalSettings['apiKey'] : 'dispatch-acceptance-key', 'monthlySendLimit' => 100,
    ]));
    $domainName = $postalCapture ? 'nexa.local.test' : 'example.test';
    $settings = $store->runWith($tenantA, fn (): array => $factory->create(MarketingDeliveryService::class)->saveDomain((object) ['domainName' => $domainName]));
    $domainId = (string) array_values(array_filter($settings['domains'], static fn (array $domain): bool => $domain['domainName'] === $domainName))[0]['id'];
    $pdo->prepare("UPDATE nexa_marketing_sending_domain SET ownership_status='verified',spf_status='verified',dkim_status='verified',dmarc_status='verified',return_path_status='verified',verified_at=NOW(6) WHERE id=? AND tenant_id=? AND service_id=?")
        ->execute([$domainId, $tenantA->tenantId, $tenantA->serviceId]);

    if ($reusableSendGovernance) {
        $dispatch = $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class));
        $customization=$factory->create(\Espo\Custom\Tools\Customization\CustomizationService::class);
        $propertyKey='approval_property_' . bin2hex(random_bytes(4));
        $property=$store->runWith($tenantA,fn ()=>$customization->saveDefinition('field',['entityType'=>'Contact','fieldKey'=>$propertyKey,'label'=>'Approval property ' . $propertyKey,'dataType'=>'text']));
        $richBody=$store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingConditionalContentService::class)->compose((object) [
            'matchMode'=>'all','conditions'=>[(object) ['field'=>'custom:' . $propertyKey,'operator'=>'has_value'],(object) ['field'=>'behavior:page.viewed','operator'=>'not_within_days','value'=>'30']],
            'matchingHtml'=>'<p><strong>Combined matching branch</strong></p>','fallbackHtml'=>'<p><em>Combined fallback branch</em></p>',
        ]))['bodyHtml'];
        $conditionalInput=clone $draftInput; $conditionalInput->name='Combined personalization approval'; $conditionalInput->bodyHtml=$richBody;
        $conditionalDrafts=[];
        foreach (['campaign','single_send'] as $mode) {
            $conditionalInput->deliveryMode=$mode;
            $created=$store->runWith($tenantA,fn ()=>$factory->create(MarketingEmailWorkspaceService::class)->save($conditionalInput));
            foreach (['submit','approve'] as $action) $store->runWith($tenantA,fn ()=>$factory->create(MarketingEmailWorkspaceService::class)->transition($created['id'],(object) ['action'=>$action]));
            $conditionalDrafts[$mode]=$created;
        }
        $store->runWith($tenantA,fn ()=>$customization->archive('field',$property['id']));
        $beforeQueues=(int)$pdo->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn();
        $beforeProfiles=(int)$pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn();
        foreach ([fn ()=>$dispatch->schedule($conditionalDrafts['campaign']['id']),fn ()=>$dispatch->reusableSend($conditionalDrafts['campaign']['id'],$contact->getId(),'archived-property-request',1),fn ()=>$dispatch->singleSend($conditionalDrafts['single_send']['id'],$contact->getId())] as $attempt) {
            $failure=null;
            try {$store->runWith($tenantA,$attempt);} catch (\Espo\Core\Exceptions\BadRequest $error) {$failure=$error->getMessage();}
            $assert($failure!==null && str_contains($failure,'archived'),'Archived conditional properties must reject scheduling and queue creation at preflight.');
        }
        $assert((int)$pdo->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn()===$beforeQueues,'Rejected personalization preflight must create no queue item.');
        $assert((int)$pdo->query('SELECT COUNT(*) FROM nexa_marketing_email_profile')->fetchColumn()===$beforeProfiles,'Rejected reusable preflight must create no copied profile.');
        $failedRequest=$pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND request_hash=?');
        $failedRequest->execute([$tenantA->tenantId,$tenantA->serviceId,hash('sha256','archived-property-request')]);
        $assert((int)$failedRequest->fetchColumn()===0,'Rejected personalization must not consume its request key.');
        $denied = static function (callable $call, string $message) use ($assert): void {
            $blocked = false;
            try {$call();} catch (\Espo\Core\Exceptions\BadRequest|NotFound|\Espo\Core\Exceptions\Forbidden) {$blocked = true;}
            $assert($blocked,$message);
        };
        $send = fn (string $key, int $version=1) => $store->runWith($tenantA,fn ()=>$dispatch->reusableSend($draft['id'],$contact->getId(),$key,$version));
        $denied(fn ()=>$send('short'),'Request keys must be bounded and explicit.');
        $denied(fn ()=>$send('wrong-version',2),'Unapproved versions must be rejected.');
        $denied(fn ()=>$store->runWith($tenantB,fn ()=>$dispatch->reusableSend($draft['id'],$contact->getId(),'foreign-tenant',1)),'Another tenant must not reuse the approved source.');
        $api = $store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\Api\PostReusableSend::class));
        $request = static function (array $body, ?string $profileId = null) use ($draft): \Espo\Core\Api\RequestWrapper {
            $psr = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','/api/v1/Nexa/marketing-emails/' . $draft['id'] . '/reusable-send')
                ->withHeader('Content-Type','application/json')->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body,JSON_THROW_ON_ERROR)));
            return new \Espo\Core\Api\RequestWrapper($psr,'',['id'=>$profileId ?? $draft['id']]);
        };
        $body = ['contactId'=>$contact->getId(),'requestKey'=>'event-request-one','sourceVersion'=>1];
        foreach ([array_replace($body,['sourceVersion'=>'1']), $body + ['subject'=>'Unapproved override'], $body + ['parameters'=>[]], $body + ['parameters'=>null]] as $invalid) $denied(fn ()=>$store->runWith($tenantA,fn ()=>$api->process($request($invalid))),'The real reusable API must reject wrong types and unapproved overrides.');
        $user = $container->getByClass(\Espo\Entities\User::class); $originalType = $user->get('type');
        try {$user->set('type','regular'); $denied(fn ()=>$send('staff-request-one'),'Non-administrators cannot authorize reusable sends.');}
        finally {$user->set('type',$originalType);}
        $otherService = new TenantContext($tenantA->tenantId,$tenantA->slug,'reusable-test','','20000000-0000-4000-8000-000000000099');
        $denied(fn ()=>$store->runWith($otherService,fn ()=>$dispatch->reusableSend($draft['id'],$contact->getId(),'foreign-service',1)),'Another service must not reuse the source.');
        $response = $store->runWith($tenantA,fn ()=>$api->process($request($body)));
        $first = json_decode((string) $response->getBody(),true,512,JSON_THROW_ON_ERROR);
        $retry = $send('event-request-one'); $second = $send('event-request-two');
        $assert(!$first['replayed'] && $retry['replayed'] && $first['queueItemId'] === $retry['queueItemId'],'Repeating an external request key must return the same queue identity.');
        $assert($first['id'] !== $second['id'] && $first['queueItemId'] !== $second['queueItemId'],'A different intentional key must receive an independent native email and queue identity.');
        $database = $container->getByClass(\Espo\Core\Utils\Config::class)->get('database');
        $concurrent = new PDO('mysql:host=' . ($database['host'] ?? '127.0.0.1') . ';port=' . ($database['port'] ?? 3306) . ';dbname=' . $database['dbname'] . ';charset=utf8mb4',$database['user'],$database['password'] ?? '',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $concurrent->exec('SET SESSION innodb_lock_wait_timeout=1'); $concurrent->beginTransaction(); $locked = false;
        try {
            $collision = $concurrent->prepare('INSERT IGNORE INTO nexa_marketing_send_request (tenant_id,service_id,request_hash,source_profile_id,source_version,contact_id,created_by_id) VALUES (?,?,?,?,?,?,?)');
            $collision->execute([$tenantA->tenantId,$tenantA->serviceId,hash('sha256','event-request-one'),$draft['id'],1,$contact->getId(),$user->getId()]);
        } catch (PDOException $error) {$locked = (int) ($error->errorInfo[1] ?? 0) === 1205;}
        finally {$concurrent->rollBack();}
        $assert($locked,'Competing request keys must be serialized by the database unique key.');
        $sourceCheck = $pdo->prepare('SELECT lifecycle_status FROM nexa_marketing_email_profile WHERE id=?');
        $sourceCheck->execute([$draft['id']]); $assert($sourceCheck->fetchColumn() === 'approved','Reusable source approval must remain unchanged.');
        $apiUser = $store->runWith($tenantA,function () use ($entityManager) {
            $record = $entityManager->getNewEntity('User'); $record->set(['userName'=>'grant-' . bin2hex(random_bytes(5)),'firstName'=>'Integration','lastName'=>'Fixture','type'=>'api','isActive'=>true]); $entityManager->saveEntity($record); return $entityManager->getEntityById('User',$record->getId());
        });
        $access = $store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingIntegrationAccess::class));
        $apiAccess = new \Espo\Custom\Tools\MarketingEmail\MarketingIntegrationAccess($entityManager,$store,$apiUser,$container->getByClass(\Espo\Core\Acl::class),new \Espo\Custom\Tools\MarketingEmail\MarketingContentIntegrity($entityManager,$store));
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireGrant($draft['id'],1)),'API users must have an explicit grant.');
        $grantApi = $store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\Api\PostIntegrationGrant::class));
        $grantPayload = ['action'=>'grant','userId'=>$apiUser->getId(),'sourceVersion'=>1,'hours'=>24];
        $store->runWith($tenantA,fn ()=>$grantApi->process($request($grantPayload)));
        foreach ([array_replace($grantPayload,['hours'=>'24']),$grantPayload + ['isAdmin'=>true],array_replace($grantPayload,['sourceVersion'=>'1'])] as $invalid) $denied(fn ()=>$store->runWith($tenantA,fn ()=>$grantApi->process($request($invalid))),'Grant API must reject permission overrides and incorrect types.');
        $catalog = $store->runWith($tenantA,fn ()=>$access->catalog($draft['id']));
        $assert(count($catalog['grants']) === 1 && $catalog['grants'][0]['userId'] === $apiUser->getId(),'Administrator grant catalogue must show only the scoped source grant.');
        $denied(fn ()=>$store->runWith($tenantB,fn ()=>$access->catalog($draft['id'])),'Foreign tenants cannot list another source grants.');
        $store->runWith($tenantA,fn ()=>$apiAccess->requireGrant($draft['id'],1));
        $ownedRequest = ['tenant_id'=>$tenantA->tenantId,'service_id'=>$tenantA->serviceId,'source_profile_id'=>$draft['id'],'source_version'=>1,'created_by_id'=>$apiUser->getId()];
        $store->runWith($tenantA,fn ()=>$apiAccess->requireRequestOwner($ownedRequest));
        $wrongOwner = $ownedRequest; $wrongOwner['created_by_id'] = 'another-api-user';
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireRequestOwner($wrongOwner)),'A valid template grant must not authorize another callers request key.');
        $wrongService = $ownedRequest; $wrongService['service_id'] = '20000000-0000-4000-8000-000000000099';
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireRequestOwner($wrongService)),'Request ownership must preserve service boundaries.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->grant($apiUser->getId(),$draft['id'],1,24)),'API users cannot grant themselves access.');
        $denied(fn ()=>$store->runWith($tenantB,fn ()=>$apiAccess->requireGrant($draft['id'],1)),'Integration grants must not cross tenant boundaries.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],2,24)),'Grants cannot authorize another source version.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],1,721)),'Grant duration must be bounded.');
        $store->runWith($tenantA,fn ()=>$access->revoke($apiUser->getId(),$draft['id'],1));
        $store->runWith($tenantA,fn ()=>$access->revoke($apiUser->getId(),$draft['id'],1));
        $grantAudit = $pdo->prepare("SELECT event_type,actor_user_id,payload_json FROM nexa_marketing_email_event WHERE profile_id=? AND tenant_id=? AND service_id=? AND event_type IN ('integration_granted','integration_revoked') ORDER BY occurred_at,id");
        $grantAudit->execute([$draft['id'],$tenantA->tenantId,$tenantA->serviceId]); $grantEvents = $grantAudit->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($grantEvents) === 2 && $grantEvents[0]['actor_user_id'] === $user->getId() && json_decode($grantEvents[0]['payload_json'],true)['userId'] === $apiUser->getId(),'Grant audit must retain the administrator and target API user; duplicate revocations append no misleading event.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireGrant($draft['id'],1)),'Revocation must take effect immediately.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireRequestOwner($ownedRequest)),'Revocation must also prevent integration replay access.');
        $store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],1,24));
        $grantState = $pdo->prepare('SELECT expires_at,modified_at,revoked_at FROM nexa_marketing_integration_grant WHERE tenant_id=? AND service_id=? AND user_id=? AND source_profile_id=? AND source_version=1');
        $grantState->execute([$tenantA->tenantId,$tenantA->serviceId,$apiUser->getId(),$draft['id']]); $beforeRejectedRenewals = $grantState->fetch(PDO::FETCH_ASSOC);
        $grantTemplate = $pdo->prepare('SELECT email_template_id FROM mass_email WHERE id=?');
        $grantTemplate->execute([$draft['massEmailId']]); $grantTemplateId = $grantTemplate->fetchColumn();
        $pdo->prepare('UPDATE email_template SET subject=? WHERE id=?')->execute(['Changed outside approval',$grantTemplateId]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],1,24)),'Grant renewal must reject native content changed outside its approved snapshot.');
        $pdo->prepare('UPDATE email_template SET subject=? WHERE id=?')->execute([$draftInput->subject,$grantTemplateId]);
        $pdo->prepare('UPDATE mass_email SET from_address=? WHERE id=?')->execute(['changed@example.test',$draft['massEmailId']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],1,24)),'Grant renewal must reject a sender changed outside its approved delivery policy.');
        $pdo->prepare('UPDATE mass_email SET from_address=? WHERE id=?')->execute([$draftInput->fromAddress,$draft['massEmailId']]);
        $pdo->prepare('UPDATE user SET is_active=0 WHERE id=?')->execute([$apiUser->getId()]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$draft['id'],1,24)),'Grant renewal must recheck the persisted API user, not a cached active flag.');
        $pdo->prepare('UPDATE user SET is_active=1 WHERE id=?')->execute([$apiUser->getId()]);
        $grantState->execute([$tenantA->tenantId,$tenantA->serviceId,$apiUser->getId(),$draft['id']]);
        $assert($grantState->fetch(PDO::FETCH_ASSOC) === $beforeRejectedRenewals,'Rejected renewals must leave the existing grant expiry and state unchanged.');
        $grantAudit->execute([$draft['id'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert(count($grantAudit->fetchAll(PDO::FETCH_ASSOC)) === 3,'Rejected renewals must not append successful grant audit events.');
        $pdo->prepare('UPDATE nexa_marketing_integration_grant SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE tenant_id=? AND service_id=? AND user_id=?')->execute([$tenantA->tenantId,$tenantA->serviceId,$apiUser->getId()]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$apiAccess->requireGrant($draft['id'],1)),'Expired grants must not authorize sending.');
        $other = $store->runWith($tenantA,fn ()=>$services->get('Contact')->create((object) ['firstName'=>'Other','lastName'=>'Recipient'],CreateParams::create()));
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatch->reusableSend($draft['id'],$other->getId(),'event-request-one',1)),'An existing request key cannot change its Contact.');
        $rows = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND source_profile_id=?');
        $rows->execute([$tenantA->tenantId,$tenantA->serviceId,$draft['id']]); $assert((int) $rows->fetchColumn() === 2,'Only successful distinct requests must create ledger rows.');
        $templateQuery = $pdo->prepare('SELECT email_template_id FROM mass_email WHERE id=?'); $templateQuery->execute([$draft['massEmailId']]); $sourceTemplateId = $templateQuery->fetchColumn();
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=?')->execute(['<p>Unapproved source change</p>',$sourceTemplateId]);
        $denied(fn ()=>$send('tampered-source-key'),'Content changed outside the versioned approval path must not be copied.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatch->schedule($draft['id'])),'Campaign scheduling must reject content changed outside its governed version.');
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=?')->execute([$draftInput->bodyHtml,$sourceTemplateId]);
        $insertLimit = $pdo->prepare('INSERT INTO nexa_marketing_send_request (tenant_id,service_id,request_hash,source_profile_id,source_version,contact_id,created_by_id) VALUES (?,?,?,?,?,?,?)');
        for ($index=0;$index<58;$index++) $insertLimit->execute([$tenantA->tenantId,$tenantA->serviceId,hash('sha256','rate-fixture-' . $index),$draft['id'],1,$contact->getId(),$user->getId()]);
        $denied(fn ()=>$send('rate-limited-key'),'New requests must respect the per-source rate limit.');
        $assert($send('event-request-one')['replayed'],'A retry must remain available at the request rate limit.');
        $pdo->prepare('DELETE FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND source_profile_id=? AND profile_id IS NULL')->execute([$tenantA->tenantId,$tenantA->serviceId,$draft['id']]);
        $parameterInput = clone $draftInput;
        $integrationFile = $store->runWith($tenantA,function () use ($entityManager,$factory) {
            $file = $entityManager->getNewEntity('Attachment');
            $contents = "Approved integration attachment.\n";
            $file->set(['name'=>'integration-guide.txt','type'=>'text/plain','size'=>strlen($contents),'storage'=>'EspoUploadDir']);
            $entityManager->saveEntity($file);
            $factory->create(\Espo\Core\FileStorage\Manager::class)->putContents($file,$contents);
            return $entityManager->getEntityById('Attachment',$file->getId());
        });
        $attachmentCleanup[] = ['tenant'=>$tenantA,'attachment'=>$integrationFile];
        $parameterInput->attachmentsIds = [$integrationFile->getId()];
        $workspace = $store->runWith($tenantA,fn ()=>$factory->create(MarketingEmailWorkspaceService::class));
        $parameterInput->subject = 'Reference [[nexa:reference:40]]';
        $parameterInput->bodyHtml = '<p>[[nexa:message:255]] {Contact.firstName}</p>';
        $parameterInput->preheader = '[[nexa:reference:40]]';
        $parameterSource = $store->runWith($tenantA,fn ()=>$workspace->save($parameterInput));
        $store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'submit']));
        $templateQuery->execute([$parameterSource['massEmailId']]); $parameterTemplateId = $templateQuery->fetchColumn();
        $pdo->prepare('UPDATE email_template SET subject=? WHERE id=?')->execute(['Changed outside editor',$parameterTemplateId]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Approval must reject native subject tampering.');
        $pdo->prepare('UPDATE email_template SET subject=? WHERE id=?')->execute([$parameterInput->subject,$parameterTemplateId]);
        $pdo->prepare('UPDATE nexa_marketing_email_profile SET preheader=? WHERE id=?')->execute(['Changed preheader',$parameterSource['id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Approval must reject profile preheader tampering.');
        $pdo->prepare('UPDATE nexa_marketing_email_profile SET preheader=? WHERE id=?')->execute([$parameterInput->preheader,$parameterSource['id']]);
        $originalHash = hash('sha256',$parameterInput->subject . "\0" . $parameterInput->preheader . "\0" . $parameterInput->bodyHtml . "\0" . ($parameterInput->bodyText ?? ''));
        $pdo->prepare('UPDATE nexa_marketing_email_version SET content_hash=? WHERE profile_id=?')->execute([str_repeat('0',64),$parameterSource['id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Approval must verify the immutable version hash.');
        $pdo->prepare('UPDATE nexa_marketing_email_version SET content_hash=? WHERE profile_id=?')->execute([$originalHash,$parameterSource['id']]);
        $nativePolicyQuery = $pdo->prepare('SELECT from_address,from_name,reply_to_address,reply_to_name,campaign_id FROM mass_email WHERE id=?');
        $nativePolicyQuery->execute([$parameterSource['massEmailId']]); $nativePolicy = $nativePolicyQuery->fetch(PDO::FETCH_ASSOC);
        foreach (['from_address'=>'changed@example.test','from_name'=>'Changed sender','reply_to_address'=>'reply@example.test','reply_to_name'=>'Changed reply name','campaign_id'=>null] as $column=>$changed) {
            $pdo->prepare('UPDATE mass_email SET ' . $column . '=? WHERE id=?')->execute([$changed,$parameterSource['massEmailId']]);
            $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Approval must reject delivery policy changes: ' . $column);
            $pdo->prepare('UPDATE mass_email SET ' . $column . '=? WHERE id=?')->execute([$nativePolicy[$column],$parameterSource['massEmailId']]);
        }
        $pdo->prepare('UPDATE nexa_marketing_email_profile SET delivery_mode=? WHERE id=?')->execute(['single_send',$parameterSource['id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Approval must reject delivery-mode changes outside the saved version.');
        $pdo->prepare('UPDATE nexa_marketing_email_profile SET delivery_mode=? WHERE id=?')->execute([$parameterInput->deliveryMode ?? 'campaign',$parameterSource['id']]);
        $manifestQuery = $pdo->prepare('SELECT delivery_manifest_json FROM nexa_marketing_email_version WHERE profile_id=? AND version_number=1');
        $manifestQuery->execute([$parameterSource['id']]); $policyManifest = $manifestQuery->fetchColumn();
        $pdo->prepare('UPDATE nexa_marketing_email_version SET delivery_manifest_json=NULL WHERE profile_id=?')->execute([$parameterSource['id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Historical versions without policy evidence must require a new save.');
        $corruptManifest = json_decode($policyManifest,true,512,JSON_THROW_ON_ERROR); $corruptManifest['sha256'] = str_repeat('0',64);
        $pdo->prepare('UPDATE nexa_marketing_email_version SET delivery_manifest_json=? WHERE profile_id=?')->execute([json_encode($corruptManifest,JSON_THROW_ON_ERROR),$parameterSource['id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Corrupt policy hashes cannot authorize sending.');
        $reorderedManifest = json_decode($policyManifest,true,512,JSON_THROW_ON_ERROR); $reorderedManifest['policy'] = array_reverse($reorderedManifest['policy'],true);
        $pdo->prepare('UPDATE nexa_marketing_email_version SET delivery_manifest_json=? WHERE profile_id=?')->execute([json_encode($reorderedManifest,JSON_THROW_ON_ERROR),$parameterSource['id']]);
        $store->runWith($tenantA,fn ()=>$workspace->transition($parameterSource['id'],(object) ['action'=>'approve']));
        $legacy = $store->runWith($tenantA,fn ()=>$workspace->save($parameterInput));
        $pdo->prepare('UPDATE nexa_marketing_email_version SET delivery_manifest_json=NULL WHERE profile_id=?')->execute([$legacy['id']]);
        $store->runWith($tenantA,fn ()=>$workspace->transition($legacy['id'],(object) ['action'=>'submit']));
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$workspace->transition($legacy['id'],(object) ['action'=>'approve'])),'A historical version must not be approved without a new saved policy snapshot.');
        $store->runWith($tenantA,fn ()=>$workspace->transition($legacy['id'],(object) ['action'=>'return']));
        $resaved = $store->runWith($tenantA,fn ()=>$workspace->save($parameterInput,$legacy['id']));
        $assert($resaved['version'] === 2,'Resaving a historical email must append a new version rather than rewriting historical approval evidence.');
        $store->runWith($tenantA,fn ()=>$workspace->transition($legacy['id'],(object) ['action'=>'submit']));
        $store->runWith($tenantA,fn ()=>$workspace->transition($legacy['id'],(object) ['action'=>'approve']));
        $values = (object) ['reference'=>'ORDER-42','message'=>'A & B confirmed'];
        $parameterSend = fn (\stdClass $input) => $store->runWith($tenantA,fn ()=>$dispatch->reusableSend($parameterSource['id'],$contact->getId(),'parameter-request-one',1,$input));
        $denied(fn ()=>$parameterSend((object) ['reference'=>'ORDER-42']),'Missing declared parameters must not queue a send.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatch->singleSend($parameterSource['id'],$contact->getId())),'Unresolved source content cannot bypass parameter delivery.');
        $parameterResponse = $store->runWith($tenantA,fn ()=>$api->process($request(['contactId'=>$contact->getId(),'sourceVersion'=>1,'requestKey'=>'parameter-request-one','parameters'=>$values],$parameterSource['id'])));
        $parameterFirst = json_decode((string) $parameterResponse->getBody(),true,512,JSON_THROW_ON_ERROR);
        $parameterRetry = $parameterSend((object) ['message'=>'A & B confirmed','reference'=>'ORDER-42']);
        $assert($parameterRetry['queueItemId'] === $parameterFirst['queueItemId'] && $parameterRetry['replayed'],'Equivalent parameters must replay the same native queue item.');
        $denied(fn ()=>$parameterSend((object) ['reference'=>'ORDER-43','message'=>'A & B confirmed']),'Changed parameter values must not reuse the request key.');
        $snapshot = $store->runWith($tenantA,fn ()=>$workspace->versions($parameterFirst['id'],1));
        $assert($snapshot['subject'] === 'Reference ORDER-42' && $snapshot['preheader'] === 'ORDER-42' && str_contains($snapshot['bodyHtml'],'A &amp; B confirmed') && str_contains($snapshot['bodyHtml'],'{Contact.firstName}'),'The native immutable child version must contain safely resolved text and retain native personalization.');
        $previewService = $store->runWith($tenantA,fn ()=>$factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingPreviewService::class));
        $renderedParameters = $store->runWith($tenantA,fn ()=>$previewService->preview((object) ['contactId'=>$contact->getId(),'subject'=>$snapshot['subject'],'bodyHtml'=>$snapshot['bodyHtml']]));
        $assert(str_contains($renderedParameters['bodyHtml'],(string) $contact->get('firstName')) && !str_contains($renderedParameters['bodyHtml'],'{Contact.firstName}'),'Native preview must render Contact personalization after safe parameter resolution.');
        $role = $store->runWith($tenantA,function () use ($entityManager,$apiUser) {
            $role = $entityManager->getNewEntity('Role'); $permissions = new \stdClass();
            foreach (['MassEmail','EmailTemplate','Contact','Attachment','Campaign'] as $scope) $permissions->{$scope} = (object) ['read'=>'all','create'=>'no','edit'=>'no','delete'=>'no','stream'=>'no'];
            $role->set(['name'=>'Read-only integration fixture','data'=>$permissions]); $entityManager->saveEntity($role);
            $entityManager->getRDBRepository('User')->getRelation($apiUser,'roles')->relate($role);
            return $role;
        });
        $nativeApiAcl = new \Espo\Core\Acl($container->getByClass(\Espo\Core\AclManager::class),$apiUser);
        $assert($store->runWith($tenantA,fn () => !$nativeApiAcl->checkScope('MassEmail','create') && !$nativeApiAcl->checkScope('MassEmail','edit') && $nativeApiAcl->checkScope('MassEmail','read')),'Integration fixture must use real native read-only role permissions.');
        $restrictedAccess = $store->runWith($tenantA,fn ()=>$factory->createWith(\Espo\Custom\Tools\MarketingEmail\MarketingIntegrationAccess::class,['actor'=>$apiUser,'acl'=>$nativeApiAcl]));
        $restrictedFiles = $store->runWith($tenantA,fn ()=>$factory->createWith(\Espo\Custom\Tools\MarketingEmail\MarketingAttachmentService::class,['acl'=>$nativeApiAcl]));
        $restrictedImages = $store->runWith($tenantA,fn ()=>$factory->createWith(\Espo\Custom\Tools\MarketingEmail\MarketingImageService::class,['user'=>$apiUser,'acl'=>$nativeApiAcl]));
        $restrictedWorkspace = $store->runWith($tenantA,fn ()=>$factory->createWith(MarketingEmailWorkspaceService::class,['user'=>$apiUser,'acl'=>$nativeApiAcl,'integrationAccess'=>$restrictedAccess,'attachments'=>$restrictedFiles,'images'=>$restrictedImages]));
        $restrictedDispatch = $store->runWith($tenantA,fn ()=>$factory->createWith(MarketingDispatchService::class,['user'=>$apiUser,'acl'=>$nativeApiAcl,'integrationAccess'=>$restrictedAccess,'workspace'=>$restrictedWorkspace,'attachments'=>$restrictedFiles,'images'=>$restrictedImages]));
        $restrictedApi = new \Espo\Custom\Tools\MarketingEmail\Api\PostReusableSend($restrictedDispatch);
        $integrationPayload = ['contactId'=>$contact->getId(),'sourceVersion'=>1,'requestKey'=>'api-parameter-send-one','parameters'=>$values];
        $integrationKey = bin2hex(random_bytes(20));
        $pdo->prepare("UPDATE user SET api_key=?,auth_method='ApiKey' WHERE id=?")->execute([$integrationKey,$apiUser->getId()]);
        $integrationMiddleware = $factory->create(\Espo\Core\Tenant\Api\TenantContextMiddleware::class);
        $integrationHandler = new class($restrictedApi,$factory,$assert,$apiUser->getId(),$parameterSource['id']) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private \Espo\Custom\Tools\MarketingEmail\Api\PostReusableSend $api,private InjectableFactory $factory,private Closure $assert,private string $userId,private string $sourceId) {}
            public function handle(\Psr\Http\Message\ServerRequestInterface $psr): \Psr\Http\Message\ResponseInterface {
                $wrapped = new \Espo\Core\Api\RequestWrapper($psr,'',['id'=>$this->sourceId]);
                $login = $this->factory->create(\Espo\Core\Authentication\Logins\ApiKey::class)->login(\Espo\Core\Authentication\Login\Data::createBuilder()->build(),$wrapped);
                ($this->assert)($login->isSuccess() && $login->getUser()?->getId() === $this->userId,'The integration action must run only after native key authentication of its API actor.');
                return $this->api->process($wrapped)->toPsr7();
            }
        };
        $integrationSend = function () use ($integrationMiddleware,$integrationHandler,$integrationKey,$integrationPayload,$parameterSource) {
            $psr = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST','http://localhost/nexa/api/v1/Nexa/marketing-emails/' . $parameterSource['id'] . '/reusable-send')->withHeader('X-Api-Key',$integrationKey)->withHeader('Content-Type','application/json');
            $psr->getBody()->write(json_encode($integrationPayload,JSON_THROW_ON_ERROR));
            $psr->getBody()->rewind();
            return $integrationMiddleware->process($psr,$integrationHandler);
        };
        $denied($integrationSend,'A read-only API user without a grant must not create a native send.');
        $store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$parameterSource['id'],1,24));
        $integrationFirst = json_decode((string) $integrationSend()->getBody(),true,512,JSON_THROW_ON_ERROR);
        $integrationRetry = json_decode((string) $integrationSend()->getBody(),true,512,JSON_THROW_ON_ERROR);
        $assert(!$integrationFirst['replayed'] && $integrationRetry['replayed'] && $integrationFirst['queueItemId'] === $integrationRetry['queueItemId'],'Granted read-only native API users must create and replay exactly one queue item.');
        $concurrent->beginTransaction();
        $sourceLocked = false;
        try {
            // Revocation takes this same source lock before touching the grant.
            $sourceLock = $concurrent->prepare('SELECT id FROM nexa_marketing_email_profile WHERE id=? AND tenant_id=? AND service_id=? FOR UPDATE');
            $sourceLock->execute([$parameterSource['id'],$tenantA->tenantId,$tenantA->serviceId]);
        } catch (PDOException $error) {$sourceLocked = (int) ($error->errorInfo[1] ?? 0) === 1205;}
        finally {$concurrent->rollBack();}
        $assert($sourceLocked,'A competing revocation must wait on the source lock held by the approved-send transaction.');
        $integrationEvidence = $pdo->prepare('SELECT p.created_by_id,p.approved_by_id,p.approved_at,v.subject,v.body_html FROM nexa_marketing_email_profile p INNER JOIN nexa_marketing_email_version v ON v.profile_id=p.id AND v.tenant_id=p.tenant_id AND v.service_id=p.service_id AND v.version_number=1 WHERE p.id=?');
        $integrationEvidence->execute([$integrationFirst['id']]); $inherited = $integrationEvidence->fetch(PDO::FETCH_ASSOC);
        $assert($inherited['created_by_id'] === $apiUser->getId() && $inherited['approved_by_id'] === $user->getId() && $inherited['approved_at'] && $inherited['subject'] === 'Reference ORDER-42' && str_contains($inherited['body_html'],'A &amp; B confirmed'),'Integration copies must retain the actual source approver and safely resolved immutable content.');
        $store->runWith($tenantA,function () use ($entityManager,$pdo,$integrationFirst,$restrictedFiles,$restrictedImages,$factory,$assert) {
            $query = $pdo->prepare('SELECT p.*,m.email_template_id FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id AND m.tenant_id=p.tenant_id AND m.service_id=p.service_id WHERE p.id=?');
            $query->execute([$integrationFirst['id']]); $profile = $query->fetch(PDO::FETCH_ASSOC);
            $template = $entityManager->getEntityById('EmailTemplate',$profile['email_template_id']);
            $mass = $entityManager->getEntityById('MassEmail',$profile['mass_email_id']);
            $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingContentIntegrity::class)->requireSnapshot($profile,$template,$mass);
            $files = $restrictedFiles->approvedFiles($profile,$template,true);
            $assert(count($files) === 1 && $files[0]->contents === "Approved integration attachment.\n",'Inherited native attachment aliases must retain usable approval manifests and original bytes.');
            $restrictedImages->requireApproved($profile,$template);
        });
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedWorkspace->save($parameterInput)),'Grants must not authorize ordinary workspace creation.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedWorkspace->save($parameterInput,$parameterSource['id'])),'Grants must not authorize source edits.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedWorkspace->transition($parameterSource['id'],(object) ['action'=>'approve'])),'Grants must not authorize source approval.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedDispatch->singleSend($integrationFirst['id'],$contact->getId())),'The ordinary single-send endpoint must remain administrator-only.');
        $denied(fn ()=>$store->runWith($tenantB,fn ()=>$restrictedApi->process($request($integrationPayload,$parameterSource['id']))),'Granted integration sends must remain tenant scoped.');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedApi->process($request(['contactId'=>$contact->getId(),'sourceVersion'=>1,'requestKey'=>'parameter-request-one','parameters'=>$values],$parameterSource['id']))),'An API grant must not authorize replaying an administrators request.');
        $store->runWith($tenantA,fn ()=>$access->revoke($apiUser->getId(),$parameterSource['id'],1));
        $denied($integrationSend,'Revoked grants must prevent even owned replay.');
        $retainedQueue = $pdo->prepare('SELECT status FROM email_queue_item WHERE id=? AND tenant_id=? AND service_id=? AND deleted=0');
        $retainedQueue->execute([$integrationFirst['queueItemId'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert($retainedQueue->fetchColumn() === 'Pending','Grant revocation must not silently cancel an already accepted queue item.');
        $integrationQueueCount = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item q INNER JOIN nexa_marketing_email_profile p ON p.mass_email_id=q.mass_email_id AND p.tenant_id=q.tenant_id AND p.service_id=q.service_id WHERE p.id=? AND p.tenant_id=? AND p.service_id=? AND q.deleted=0');
        $integrationQueueCount->execute([$integrationFirst['id'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert((int) $integrationQueueCount->fetchColumn() === 1,'Denied replay after revocation must not create another native queue item.');
        $store->runWith($tenantA,fn ()=>$access->grant($apiUser->getId(),$parameterSource['id'],1,24));
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedApi->process($request(array_replace($integrationPayload,['requestKey'=>'api-rejected-values','parameters'=>(object) ['reference'=>'ORDER-42']]),$parameterSource['id']))),'Granted API users must still provide every declared parameter.');
        $failedLedger = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_send_request WHERE tenant_id=? AND service_id=? AND request_hash=?');
        $failedLedger->execute([$tenantA->tenantId,$tenantA->serviceId,hash('sha256','api-rejected-values')]);
        $assert((int) $failedLedger->fetchColumn() === 0,'Rejected API input must not consume an intentional request key.');
        $childProfileQuery = $pdo->prepare('SELECT p.*,m.email_template_id FROM nexa_marketing_email_profile p INNER JOIN mass_email m ON m.id=p.mass_email_id WHERE p.id=?');
        $childProfileQuery->execute([$parameterFirst['id']]); $childProfile = $childProfileQuery->fetch(PDO::FETCH_ASSOC);
        $childMass = $store->runWith($tenantA,fn ()=>$entityManager->getEntityById('MassEmail',$childProfile['mass_email_id']));
        $childTemplate = $store->runWith($tenantA,fn ()=>$entityManager->getEntityById('EmailTemplate',$childProfile['email_template_id']));
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=?')->execute(['<p>Changed after queueing</p>',$childProfile['email_template_id']]);
        $dispatchBoundary = new ReflectionMethod($dispatch,'dispatchItem');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatchBoundary->invoke($dispatch,$tenantA,$childProfile,$childMass,$childTemplate,$parameterFirst['queueItemId'],$contact->getId())),'Final dispatch must reject persisted tampering even if an ORM entity still holds cached approved content.');
        $pdo->prepare('UPDATE email_template SET body=? WHERE id=?')->execute([$snapshot['bodyHtml'],$childProfile['email_template_id']]);
        $pdo->prepare('UPDATE mass_email SET from_name=? WHERE id=?')->execute(['Changed after approval',$childProfile['mass_email_id']]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatchBoundary->invoke($dispatch,$tenantA,$childProfile,$childMass,$childTemplate,$parameterFirst['queueItemId'],$contact->getId())),'Final dispatch must reject persisted sender changes even with cached approved native settings.');
        $pdo->prepare('UPDATE mass_email SET from_name=? WHERE id=?')->execute([$childMass->get('fromName'),$childProfile['mass_email_id']]);
        $previousReplyName = $childMass->get('replyToName'); $childMass->set('replyToName','Changed cached reply name');
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatchBoundary->invoke($dispatch,$tenantA,$childProfile,$childMass,$childTemplate,$parameterFirst['queueItemId'],$contact->getId())),'Final dispatch must verify the actual in-memory sender settings used by the provider request.');
        $childMass->set('replyToName',$previousReplyName);
        $changedPurpose = $childProfile; $changedPurpose['purpose_id'] = 'unapproved-purpose';
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$dispatchBoundary->invoke($dispatch,$tenantA,$changedPurpose,$childMass,$childTemplate,$parameterFirst['queueItemId'],$contact->getId())),'Final dispatch must reject stale or changed consent purpose in memory.');
        $pdo->prepare('UPDATE contact SET do_not_contact=1 WHERE id=?')->execute([$contact->getId()]);
        $denied(fn ()=>$store->runWith($tenantA,fn ()=>$restrictedApi->process($request(array_replace($integrationPayload,['requestKey'=>'api-suppressed-send']),$parameterSource['id']))),'Granted integrations must not bypass contact suppression.');
        $denied(fn ()=>$send('blocked-request-three'),'Ineligible recipients must not create another dispatch.');
        $store->runWith($tenantA,fn ()=>$dispatch->runScheduled());
        $assert($send('event-request-one')['status'] === 'Failed','The real worker must recheck consent; failed retries must never recreate delivery.');
        $check = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE profile_id IN (?,?)');
        $check->execute([$first['id'],$second['id']]); $assert((int) $check->fetchColumn() === 0,'Revoked recipients must never reserve provider submissions.');
        $check->execute([$integrationFirst['id'],$parameterFirst['id']]); $assert((int) $check->fetchColumn() === 0,'The native worker must also recheck suppression on inherited integration sends without provider submission.');
        echo "Reusable approved source, native copies, scoped request keys and worker consent tests passed.\n";
        return;
    }
    if ($singleSendGovernance) {
        $dispatch = $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class));
        $expectBlocked = static function (callable $operation, string $message) use ($assert): void {
            $blocked = false;
            try { $operation(); } catch (\Espo\Core\Exceptions\BadRequest|NotFound|\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
            $assert($blocked, $message);
        };
        $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->schedule($draft['id'])), 'Single-send must never schedule the entire audience.');
        $expectBlocked(fn () => $store->runWith($tenantB, fn () => $dispatch->singleSend($draft['id'], $contact->getId())), 'Another tenant must not claim the email.');
        $otherService = new TenantContext($tenantA->tenantId, $tenantA->slug, 'single-send-test', '', '20000000-0000-4000-8000-000000000099');
        $expectBlocked(fn () => $store->runWith($otherService, fn () => $dispatch->singleSend($draft['id'], $contact->getId())), 'Another service must not claim the email.');
        $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], 'missing-contact')), 'Unknown Contact must be rejected.');
        $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='draft' WHERE id=?")->execute([$draft['id']]);
        $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $contact->getId())), 'Unapproved single-send must be rejected.');
        $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='approved' WHERE id=?")->execute([$draft['id']]);
        $pdo->prepare('UPDATE contact SET do_not_contact=1 WHERE id=?')->execute([$contact->getId()]);
        $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $contact->getId())), 'Do not contact must block queue creation.');
        $pdo->prepare('UPDATE contact SET do_not_contact=0 WHERE id=?')->execute([$contact->getId()]);
        $first = $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $contact->getId()));
        $api = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\Api\PostSingleSend::class));
        $request = static function (array $body) use ($draft): \Espo\Core\Api\RequestWrapper {
            $psr = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/Nexa/marketing-emails/' . $draft['id'] . '/single-send')
                ->withHeader('Content-Type', 'application/json')
                ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
            return new \Espo\Core\Api\RequestWrapper($psr, '', ['id' => $draft['id']]);
        };
        foreach ([[], ['contactId'=>123], ['contactId'=>$contact->getId(),'to'=>'override@example.test'], ['contactId'=>$contact->getId(),'bodyHtml'=>'Unapproved']] as $invalid) {
            $expectBlocked(fn () => $store->runWith($tenantA, fn () => $api->process($request($invalid))), 'The API must reject incomplete requests and recipient/content overrides.');
        }
        $user = $container->getByClass(\Espo\Entities\User::class);
        $originalType = $user->get('type');
        try {
            $user->set('type', 'regular');
            $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $contact->getId())), 'Non-administrators must not invoke single-send.');
        } finally { $user->set('type', $originalType); }
        $response = $store->runWith($tenantA, fn () => $api->process($request(['contactId'=>$contact->getId()])));
        $again = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        // A second connection cannot claim the profile while this transaction owns its row lock.
        $database = $container->getByClass(\Espo\Core\Utils\Config::class)->get('database');
        $concurrent = new PDO('mysql:host=' . ($database['host'] ?? '127.0.0.1') . ';port=' . ($database['port'] ?? 3306) . ';dbname=' . $database['dbname'] . ';charset=utf8mb4', $database['user'], $database['password'] ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $concurrent->exec('SET SESSION innodb_lock_wait_timeout=1');
        $concurrent->beginTransaction();
        $lockBlocked = false;
        try {
            $claim = $concurrent->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='sending' WHERE id=? AND tenant_id=? AND service_id=? AND lifecycle_status='approved' AND delivery_mode='single_send'");
            $claim->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        } catch (PDOException $error) { $lockBlocked = (int) ($error->errorInfo[1] ?? 0) === 1205; }
        finally { $concurrent->rollBack(); }
        $assert($lockBlocked, 'Concurrent state claims must wait for the locked profile, not create a second queue item.');
        $assert(!$first['replayed'] && $again['replayed'] && $first['queueItemId'] === $again['queueItemId'], 'Repeated requests must return the same native queue identity.');
        $queue = $pdo->prepare('SELECT COUNT(*) FROM email_queue_item WHERE mass_email_id=? AND tenant_id=? AND service_id=? AND is_test=0');
        $queue->execute([$draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $queue->fetchColumn() === 1, 'Exactly one customer queue item must exist.');
        $other = $store->runWith($tenantA, fn () => $services->get('Contact')->create((object) ['firstName'=>'Other', 'lastName'=>'Recipient'], CreateParams::create()));
        $expectBlocked(fn () => $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $other->getId())), 'A retry cannot change the recipient.');
        // Revoke eligibility before running the real worker: no provider call or real email is allowed.
        $pdo->prepare('UPDATE contact SET do_not_contact=1 WHERE id=?')->execute([$contact->getId()]);
        $store->runWith($tenantA, fn () => $dispatch->runScheduled());
        $replay = $store->runWith($tenantA, fn () => $dispatch->singleSend($draft['id'], $contact->getId()));
        $assert($replay['replayed'] && $replay['status'] === 'Failed', 'Worker eligibility recheck must fail safely; retries must not recreate failed deliveries.');
        $submissions = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE profile_id=? AND tenant_id=? AND service_id=?');
        $submissions->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $submissions->fetchColumn() === 0, 'Rejected single-send must never reserve or submit provider delivery.');
        echo "Native single-send queue, replay, tenant/service and worker consent governance passed.\n";
        return;
    }

    if (defined('NEXA_POSTAL_TEST_SEND_ACCEPTANCE') && NEXA_POSTAL_TEST_SEND_ACCEPTANCE === true) {
        $admin = $store->runWith($tenantA, function () use ($entityManager) {
            $user = $entityManager->getNewEntity('User');
            $user->set(['userName' => 'postal-test-' . bin2hex(random_bytes(6)), 'type' => 'admin', 'isActive' => true, 'emailAddress' => 'postal-test-admin@nexa.local.test']);
            $entityManager->saveEntity($user);
            return $entityManager->getEntityById('User', $user->getId());
        });
        $dispatcher = $factory->createWith(MarketingDispatchService::class, ['user' => $admin]);
        $admin->set('type', 'regular');
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $dispatcher->sendTest($draft['id'], $contact->getId())); } catch (\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
        $assert($blocked, 'A non-administrator must not send provider tests.');
        $admin->set('type', 'admin');
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $dispatcher->sendTest($draft['id'], 'missing-contact')); } catch (NotFound) { $blocked = true; }
        $assert($blocked, 'Unavailable personalization records must not create a send.');
        $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='draft' WHERE id=? AND tenant_id=? AND service_id=?")->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $dispatcher->sendTest($draft['id'], $contact->getId())); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Unapproved email must not be sent through the test endpoint.');
        $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='approved' WHERE id=? AND tenant_id=? AND service_id=?")->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $usage = $pdo->prepare("SELECT COALESCE(SUM(accepted_count),0) FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
        $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $before = (int) $usage->fetchColumn();
        $result = $store->runWith($tenantA, fn () => $dispatcher->sendTest($draft['id'], $contact->getId()));
        $assert($result['status'] === 'submitted' && $result['recipient'] === 'postal-test-admin@nexa.local.test', 'Postal must accept a test to the authenticated administrator only.');
        $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $usage->fetchColumn() === $before + 1, 'The test must consume one monthly send.');
        $queue = $pdo->prepare('SELECT status,target_type,target_id,is_test FROM email_queue_item WHERE mass_email_id=? AND tenant_id=? AND service_id=?');
        $queue->execute([$draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
        $items = $queue->fetchAll(PDO::FETCH_ASSOC);
        $assert(count($items) === 1 && $items[0]['status'] === 'Sent' && $items[0]['target_type'] === 'User' && $items[0]['target_id'] === $admin->getId() && (int) $items[0]['is_test'] === 1, 'The test must reuse the native User test queue.');
        $workspace = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->getWorkspace());
        $email = array_values(array_filter($workspace['emails'], fn ($item) => $item['id'] === $draft['id']))[0];
        $assert($email['lifecycleStatus'] === 'approved' && $email['queuedCount'] === 0 && $email['deliveryMetrics']['submitted'] === 0, 'A test must not advance the campaign or contaminate customer metrics.');
        $report = $store->runWith($tenantA, fn () => $factory->create(MarketingEmailWorkspaceService::class)->deliveryMessages($draft['id']));
        $assert($report['items'] === [], 'Customer reports must exclude administrator tests.');
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $dispatcher->sendTest($draft['id'], $contact->getId())); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Repeated tests within one minute must be rejected.');
        $blocked = false;
        try { $store->runWith($tenantB, fn () => $dispatcher->sendTest($draft['id'], $contact->getId())); } catch (NotFound) { $blocked = true; }
        $assert($blocked, 'Another tenant must not send this test.');
        $subjectQuery = $pdo->prepare('SELECT subject FROM email_template WHERE id=(SELECT email_template_id FROM mass_email WHERE id=?)');
        $subjectQuery->execute([$draft['massEmailId']]);
        $subject = '[Test] ' . $subjectQuery->fetchColumn();
        $captured = false;
        for ($attempt = 0; $attempt < 60 && !$captured; $attempt++) {
            $messages = json_decode(file_get_contents('http://localhost:8026/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($messages['messages'] ?? [] as $mail) {
                if (($mail['Subject'] ?? '') !== $subject) continue;
                $detail = json_decode(file_get_contents('http://localhost:8026/api/v1/message/' . rawurlencode($mail['ID'])), true, 512, JSON_THROW_ON_ERROR);
                $assert(str_contains($detail['HTML'] ?? '', 'Hello Governed') && str_contains($detail['Text'] ?? '', 'Hello Governed'), 'The real Postal test must contain native Contact personalization in both MIME alternatives.');
                $assert(($detail['To'][0]['Address'] ?? '') === 'postal-test-admin@nexa.local.test', 'The preview Contact must never receive the test.');
                if ($attachmentDelivery) {
                    $assert(($detail['Attachments'][0]['FileName'] ?? '') === 'marketing-guide.txt', 'The approved attachment must arrive in the administrator capture inbox.');
                    $raw = file_get_contents('http://localhost:8026/api/v1/message/' . rawurlencode($mail['ID']) . '/raw');
                    $assert(str_contains($raw, base64_encode("Approved marketing guide.\n")) || str_contains($raw, 'Approved marketing guide.'), 'Captured attachment bytes must match the approved fixture.');
                }
                $captured = true;
                break;
            }
            if (!$captured) usleep(500000);
        }
        $assert($captured, 'The Postal test did not reach the local capture inbox.');
        echo "Postal administrator test-send acceptance passed.\n";
        return;
    }

    if ($attachmentDelivery) {
        $sourceQuery = $pdo->prepare('SELECT email_template_id FROM mass_email WHERE id=?');
        $sourceQuery->execute([$draft['massEmailId']]);
        $template = $store->runWith($tenantA, fn () => $entityManager->getEntityById('EmailTemplate', $sourceQuery->fetchColumn()));
        $attachmentId = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingAttachmentService::class)->templateIds($template))[0];
        $usageCheck = $pdo->prepare("SELECT COALESCE(SUM(accepted_count),0) FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
        $usageCheck->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $uncharged = (int) $usageCheck->fetchColumn();
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['changed-after-approval.txt', $attachmentId, $tenantA->tenantId, $tenantA->serviceId]);
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->schedule($draft['id'])); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'A changed attachment must block scheduling before any queue reservation or provider submission.');
        $usageCheck->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $usageCheck->fetchColumn() === $uncharged, 'Attachment preflight rejection must not charge the monthly allowance.');
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['marketing-guide.txt', $attachmentId, $tenantA->tenantId, $tenantA->serviceId]);
    }
    if ($imageGovernance) {
        $approvedImages = $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingImageService::class)->versionManifest($draft['id'], 1, true));
        $assert($approvedImages === $savedImages, 'Approval must preserve the saved image checksum evidence.');
        $usageCheck = $pdo->prepare("SELECT COALESCE(SUM(accepted_count),0) FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=? AND usage_month=DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01')");
        $usageCheck->execute([$tenantA->tenantId,$tenantA->serviceId]); $uncharged = (int) $usageCheck->fetchColumn();
        $pdo->prepare('UPDATE nexa_marketing_image_publication SET checksum_sha256=? WHERE id=?')->execute([str_repeat('0',64),$publicationId]);
        $blocked = false;
        try {$store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->schedule($draft['id']));}
        catch (\Espo\Core\Exceptions\BadRequest) {$blocked = true;}
        $assert($blocked, 'Image corruption must block scheduling before queue creation.');
        $usageCheck->execute([$tenantA->tenantId,$tenantA->serviceId]);
        $assert((int) $usageCheck->fetchColumn() === $uncharged, 'An image preflight failure must not charge monthly allowance.');
        $pdo->prepare('UPDATE nexa_marketing_image_publication SET checksum_sha256=? WHERE id=?')->execute([$savedImages[0]['sha256'],$publicationId]);
    }
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
    if ($imageGovernance) {
        $store->runWith($tenantA, fn () => $factory->create(\Espo\Custom\Tools\MarketingEmail\MarketingImageService::class)->revoke($hostedPublication['id']));
        $pdo->prepare('UPDATE mass_email SET start_at=? WHERE id=? AND tenant_id=? AND service_id=?')->execute([gmdate('Y-m-d H:i:s', time()-60),$draft['massEmailId'],$tenantA->tenantId,$tenantA->serviceId]);
        $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
        $profile->execute([$draft['id'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert($profile->fetchColumn() === 'failed', 'Revoking a scheduled image must fail the campaign before provider submission.');
        $query = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE profile_id=? AND tenant_id=? AND service_id=?');
        $query->execute([$draft['id'],$tenantA->tenantId,$tenantA->serviceId]);
        $assert((int) $query->fetchColumn() === 0, 'Revoked image delivery must not produce provider submissions.');
        $usageCheck->execute([$tenantA->tenantId,$tenantA->serviceId]);
        $assert((int) $usageCheck->fetchColumn() === $uncharged, 'Revocation before dispatch must not consume monthly allowance.');
        echo "Hosted image approval, scheduling and worker-failure tests passed.\n";
        return;
    }

    if (defined('NEXA_MARKETING_ATTACHMENT_SCHEDULED_FAILURE_TEST')) {
        $pdo->prepare('UPDATE attachment SET name=? WHERE id=? AND tenant_id=? AND service_id=?')->execute(['changed-after-scheduling.txt', $attachmentId, $tenantA->tenantId, $tenantA->serviceId]);
        $pdo->prepare('UPDATE mass_email SET start_at=? WHERE id=? AND tenant_id=? AND service_id=?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $draft['massEmailId'], $tenantA->tenantId, $tenantA->serviceId]);
        $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
        $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert($profile->fetchColumn() === 'failed', 'A changed scheduled attachment must fail the campaign before provider submission.');
        $submissionCheck = $pdo->prepare('SELECT COUNT(*) FROM nexa_marketing_delivery_message WHERE profile_id=? AND tenant_id=? AND service_id=?');
        $submissionCheck->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $submissionCheck->fetchColumn() === 0, 'A failed attachment preflight must not create a provider submission reservation.');
        $usageCheck->execute([$tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $usageCheck->fetchColumn() === $uncharged, 'Post-scheduling attachment changes must not consume monthly allowance.');
        $store->runWith($tenantA, fn () => $factory->create(MarketingDispatchService::class)->runScheduled());
        $submissionCheck->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
        $assert((int) $submissionCheck->fetchColumn() === 0, 'The failed campaign must not silently retry on the next worker run.');
        echo "Scheduled attachment integrity failure, no-send and no-charge acceptance passed.\n";
        return;
    }

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
        $reportService = $factory->create(MarketingEmailWorkspaceService::class);
        $report = $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], 'governed-recipient', 'submitted'));
        $assert(count($report['items']) === 1 && $report['items'][0]['recipient'] === 'governed-recipient@example.test', 'The real submitted recipient must appear in the scoped report.');
        $after = $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], '', '', $report['items'][0]['id']));
        $assert(count($after['items']) === 0, 'The delivery cursor must advance without repeating the preceding message.');
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], '', '', 'invalid-cursor')); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
        $assert($blocked, 'Invalid recipient report cursors must be rejected.');
        $report = $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], '', 'bounced'));
        $assert(count($report['items']) === 0, 'Delivery status filters must not return other outcomes.');
        $report = $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], '%'));
        $assert(count($report['items']) === 0, 'Literal wildcard searches must not match every recipient.');
        $blocked = false;
        try { $store->runWith($tenantB, fn () => $reportService->deliveryMessages($draft['id'])); } catch (NotFound) { $blocked = true; }
        $assert($blocked, 'Another tenant must not see delivery recipients.');
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
                $assert(str_contains($detail['Text'] ?? '', 'Hello Governed'), 'The plain-text alternative must contain personalized Contact content.');
                $assert(str_contains($detail['Text'] ?? '', '/Nexa/public/marketing-unsubscribe/'), 'The plain-text alternative must preserve the unsubscribe URL.');
                $assert(!str_contains($detail['Text'] ?? '', '<p>'), 'HTML markup must not leak into the text alternative.');
                $assert(str_contains($detail['HTML'] ?? '', 'data-nexa-preheader="true"'), 'The inbox preheader must be included in delivered HTML.');
                $assert(str_contains($detail['HTML'] ?? '', 'Preview for Governed &lt;offer&gt;'), 'The preheader must use native personalization and escape markup.');
                $assert(!str_contains($detail['Text'] ?? '', 'Preview for'), 'The hidden inbox preheader must not pollute the text alternative.');
                if ($attachmentDelivery) {
                    $assert(count($detail['Attachments'] ?? []) === 1 && ($detail['Attachments'][0]['FileName'] ?? '') === 'marketing-guide.txt', 'Scheduled campaign delivery must include the approved attachment exactly once.');
                    $assert(($detail['To'][0]['Address'] ?? '') === 'governed-recipient@example.test', 'Only the synthetic consented campaign Contact may receive the capture message.');
                    $raw = file_get_contents('http://localhost:8026/api/v1/message/' . rawurlencode($mail['ID']) . '/raw');
                    $assert(str_contains($raw, base64_encode("Approved marketing guide.\n")) || str_contains($raw, 'Approved marketing guide.'), 'Campaign attachment bytes must match the approved fixture.');
                }
                if (defined('NEXA_POSTAL_PLAIN_TEXT_ACCEPTANCE') && NEXA_POSTAL_PLAIN_TEXT_ACCEPTANCE === true) {
                    $assert(str_contains($detail['Text'] ?? '', '<customer>'), 'Native plain-text content must remain literal.');
                    $assert(str_contains($detail['HTML'] ?? '', '&lt;customer&gt;'), 'Native plain-text markup must be escaped in the HTML counterpart.');
                }
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
            $deliveredReport = $store->runWith($tenantA, fn () => $reportService->deliveryMessages($draft['id'], '', 'delivered'));
            $assert(count($deliveredReport['items']) === 1, 'Recipient reports must reflect the real reconciled delivery state.');
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

    $pdo->prepare("UPDATE nexa_marketing_email_profile SET lifecycle_status='sending' WHERE id=?")->execute([$draft['id']]);
    $hold = new ReflectionMethod(MarketingDispatchService::class, 'holdUncertainSubmission');
    $fixtureProfile = ['id' => $draft['id'], 'mass_email_id' => $draft['massEmailId'], 'version_number' => 1];
    $pdo->prepare("INSERT INTO nexa_marketing_monthly_usage (tenant_id,service_id,usage_month,accepted_count) VALUES (?,?,DATE_FORMAT(CURRENT_DATE(),'%Y-%m-01'),3) ON DUPLICATE KEY UPDATE accepted_count=accepted_count+3")
        ->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $usage = $pdo->prepare("SELECT COALESCE(SUM(accepted_count),0) FROM nexa_marketing_monthly_usage WHERE tenant_id=? AND service_id=?");
    $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $reservedBefore = (int) $usage->fetchColumn();
    $hold->invoke($factory->create(MarketingDispatchService::class), $tenantB, $fixtureProfile, $item->getId());
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'sending', 'A foreign tenant cannot pause an uncertain submission.');
    $hold->invoke($factory->create(MarketingDispatchService::class), $tenantA, $fixtureProfile, $item->getId());
    $hold->invoke($factory->create(MarketingDispatchService::class), $tenantA, $fixtureProfile, $item->getId());
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'paused', 'Lost provider responses must pause immediately.');
    $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $usage->fetchColumn() === $reservedBefore, 'An uncertain response must retain its monthly reservation.');
    $queueStatus = $pdo->prepare('SELECT status FROM email_queue_item WHERE id=?');
    $queueStatus->execute([$item->getId()]);
    $assert($queueStatus->fetchColumn() === 'Sending', 'An uncertain queue item must remain held, not be reset for retry.');
    $audit->execute([$draft['id']]);
    $assert((int) $audit->fetchColumn() === 2, 'Repeated uncertain-response handling must emit only one additional review event.');

    $reviewService = $factory->create(MarketingDispatchService::class);
    $blocked = false;
    try { $store->runWith($tenantB, fn () => $reviewService->reviewSubmissions($draft['id'])); } catch (NotFound) { $blocked = true; }
    $assert($blocked, 'A foreign tenant cannot inspect held recipients.');
    $review = $store->runWith($tenantA, fn () => $reviewService->reviewSubmissions($draft['id']));
    $assert(count($review['items']) === 1, 'The held native queue item must be reviewable.');
    $resolution = (object) ['messageId' => '70000000-0000-4000-8000-000000000001', 'decision' => 'not_accepted', 'evidenceNote' => 'Postal administrator confirmed no accepted message in the provider logs.'];
    $blocked = false;
    try { $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], (object) ['decision' => 'not_accepted', 'evidenceNote' => ''])); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
    $assert($blocked, 'A resolution must require provider evidence.');
    $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], $resolution));
    $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $usage->fetchColumn() === $reservedBefore - 1, 'Confirmed non-acceptance must release exactly one reservation.');
    $blocked = false;
    try { $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], $resolution)); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
    $assert($blocked, 'Repeated resolution must not release the allowance twice.');
    $profile->execute([$draft['id'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert($profile->fetchColumn() === 'paused', 'Review must not automatically resume delivery.');

    $acceptedItem = $store->runWith($tenantA, function () use ($entityManager, $draft, $contact) {
        $item = $entityManager->getNewEntity('EmailQueueItem');
        $item->set(['massEmailId' => $draft['massEmailId'], 'targetId' => $contact->getId(), 'targetType' => 'Contact', 'status' => 'Sending']);
        $entityManager->saveEntity($item);
        return $item;
    });
    $pdo->prepare("INSERT INTO nexa_marketing_delivery_message (id,tenant_id,service_id,profile_id,email_queue_item_id,provider_key,recipient,status) VALUES (?,?,?,?,?,'postal','governed-recipient@example.test','submitting')")
        ->execute(['70000000-0000-4000-8000-000000000002', $tenantA->tenantId, $tenantA->serviceId, $draft['id'], $acceptedItem->getId()]);
    $acceptedResolution = (object) ['messageId' => '70000000-0000-4000-8000-000000000002', 'decision' => 'accepted', 'providerMessageId' => 'postal-reviewed-acceptance', 'evidenceNote' => 'Postal logs confirm acceptance for this exact recipient and queue header.'];
    $user = $container->getByClass(\Espo\Entities\User::class);
    $originalType = $user->get('type');
    try {
        $user->set('type', 'regular');
        $blocked = false;
        try { $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], $acceptedResolution)); } catch (\Espo\Core\Exceptions\Forbidden) { $blocked = true; }
        $assert($blocked, 'A non-administrator cannot resolve held submissions.');
    } finally { $user->set('type', $originalType); }
    $blocked = false;
    try { $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], $acceptedResolution)); } catch (\Espo\Core\Exceptions\BadRequest) { $blocked = true; }
    $assert($blocked, 'A live in-flight request cannot be manually resolved before uncertainty or staleness is established.');
    $hold->invoke($reviewService, $tenantA, $fixtureProfile, $acceptedItem->getId());
    $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $acceptedUsage = (int) $usage->fetchColumn();
    $store->runWith($tenantA, fn () => $reviewService->resolveSubmission($draft['id'], $acceptedResolution));
    $queueStatus->execute([$acceptedItem->getId()]);
    $assert($queueStatus->fetchColumn() === 'Sent', 'Confirmed acceptance must reconcile the held native queue, without resending.');
    $usage->execute([$tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $usage->fetchColumn() === $acceptedUsage, 'Confirmed acceptance must preserve allowance usage.');

    $isolated = false;
    try {
        $store->runWith($tenantB, fn (): array => $factory->create(MarketingDispatchService::class)->schedule((string) $draft['id']));
    } catch (NotFound) {
        $isolated = true;
    }
    $assert($isolated, 'Tenant B could schedule Tenant A marketing email.');
    echo "Tenant marketing dispatch tests passed.\n";
} finally {
    try {
        foreach ($attachmentCleanup as $item) $store->runWith($item['tenant'], fn () => $factory->create(\Espo\Core\FileStorage\Manager::class)->unlink($item['attachment']));
    } finally { $transactions->rollback(); }
}
