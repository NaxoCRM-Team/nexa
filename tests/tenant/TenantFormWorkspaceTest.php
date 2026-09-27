<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantResolver;
use Espo\Custom\Tools\Form\FormWorkspaceService;
use Espo\Custom\Tools\Form\PublicFormRuntimeService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$tenantA = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'tenant-form-test');
$tenantB = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'tenant-form-test');
$application = new Application();
$application->setupSystemUser();
$container = $application->getContainer();
$entityManager = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$recordServices = $container->getByClass(ServiceContainer::class);
$pdo = $entityManager->getPDO();
$transactionManager = $entityManager->getTransactionManager();

$transactionManager->start();
try {
    $purposeId = 'f0000000-0000-4000-8000-000000000001';
    $purposeInsert = $pdo->prepare("INSERT IGNORE INTO nexa_consent_purpose (id,tenant_id,service_id,purpose_key,name,default_legal_basis,channels_json,policy_version,is_active) VALUES (?,?,?,?,?,?,?,?,1)");
    $purposeInsert->execute([$purposeId, $tenantA->tenantId, $tenantA->serviceId, 'form_runtime_test', 'Form runtime test', 'consent', '[\"email\"]', 'test-1']);
    $beforeB = $store->runWith($tenantB, fn (): array => $factory->create(FormWorkspaceService::class)->getWorkspace());
    $created = $store->runWith($tenantA, fn (): array => $factory->create(FormWorkspaceService::class)->save((object) [
        'name' => 'Alpha consultation request',
        'description' => 'Captures qualified consultation enquiries.',
        'title' => 'Talk to our team',
        'formTheme' => 'Violet',
        'intro' => 'Tell us how we can help.',
        'fields' => [
            (object) ['name' => 'firstName', 'required' => true],
            (object) ['name' => 'lastName', 'required' => true],
            (object) ['name' => 'emailAddress', 'required' => true],
        ],
        'duplicateCheck' => true,
        'leadSource' => 'Web Site',
        'successMessage' => 'Thank you.',
        'frameAncestors' => ['https://alpha.example'],
        'consentPurposeId' => $purposeId,
        'consentChannel' => 'email',
        'consentLabel' => 'I agree to receive relevant email updates.',
    ]));
    $id = (string) ($created['savedId'] ?? '');
    $assert($id !== '', 'Creating a form did not return its native Lead Capture ID.');
    $published = $store->runWith($tenantA, fn (): array => $factory->create(FormWorkspaceService::class)->publish($id));
    $alphaForm = array_values(array_filter($published['forms'], static fn (array $form): bool => $form['id'] === $id))[0] ?? null;
    $assert($alphaForm !== null && $alphaForm['status'] === 'published', 'The form was not published.');
    $assert($alphaForm['version'] === 1 && $alphaForm['hasUnpublishedChanges'] === false, 'Published form version state is incorrect.');
    $assert(($alphaForm['configuration']['formTheme'] ?? null) === 'Violet', 'The native form theme was not retained in the published configuration.');
    $themeQuery = $pdo->prepare('SELECT form_theme FROM lead_capture WHERE id = ? AND tenant_id = ? AND service_id = ?');
    $themeQuery->execute([$id, $tenantA->tenantId, $tenantA->serviceId]);
    $assert($themeQuery->fetchColumn() === 'Violet', 'Publishing did not write the selected theme to native Lead Capture.');
    $assert(str_contains((string) $alphaForm['formUrl'], 'entryPoint=LeadCaptureForm'), 'Published form must use the native Lead Capture entry point.');
    parse_str((string) parse_url((string) $alphaForm['formUrl'], PHP_URL_QUERY), $formQuery);
    $resolvedTenant = $container->getByClass(TenantResolver::class)->resolveLeadCaptureFormId((string) ($formQuery['id'] ?? ''));
    $assert($resolvedTenant?->tenantId === $tenantA->tenantId, 'The public form ID did not resolve its owning tenant.');
    $runtime = $factory->create(PublicFormRuntimeService::class);
    $consentRejected = false;
    try {
        $store->runWith($tenantA, function () use ($entityManager, $runtime, $id): void {
            $runtime->validateSubmission($entityManager->getEntityById('LeadCapture', $id), (object) ['nexaConsentAccepted' => false]);
        });
    } catch (BadRequest) {
        $consentRejected = true;
    }
    $assert($consentRejected, 'A published form accepted a submission without its required consent.');
    $submissionKey = 'f1000000-0000-4000-8000-000000000001';
    $store->runWith($tenantA, function () use ($entityManager, $runtime, $id, $submissionKey, $purposeId): void {
        $runtime->recordSubmission($entityManager->getEntityById('LeadCapture', $id), [
            'targetId' => 'formRuntimeLead01',
            'targetType' => 'Lead',
            'data' => (object) [
                'nexaSubmissionKey' => $submissionKey,
                'nexaVisitorId' => 'test-visitor',
                'nexaSourcePage' => 'https://alpha.example/request-demo',
                'nexaReferrer' => 'https://alpha.example/',
                'nexaConsentPurposeId' => $purposeId,
                'nexaConsentChannel' => 'email',
                'nexaConsentPolicyVersion' => 'test-1',
                'nexaConsentAccepted' => true,
                'nexaFormVersion' => 1,
            ],
        ]);
    });
    $eventQuery = $pdo->prepare('SELECT tenant_id,service_id,event_type,consent_status FROM nexa_form_event WHERE submission_key=?');
    $eventQuery->execute([$submissionKey]);
    $event = $eventQuery->fetch(PDO::FETCH_ASSOC);
    $assert($event && $event['tenant_id'] === $tenantA->tenantId && $event['service_id'] === $tenantA->serviceId, 'Form submission evidence was not written to its tenant and service.');
    $assert($event['event_type'] === 'submission' && $event['consent_status'] === 'granted', 'Form submission consent evidence is incomplete.');
    $tenantBEventCount = $store->runWith($tenantB, function () use ($pdo, $tenantB, $submissionKey): int {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM nexa_form_event WHERE tenant_id=? AND service_id=? AND submission_key=?');
        $statement->execute([$tenantB->tenantId, $tenantB->serviceId, $submissionKey]);
        return (int) $statement->fetchColumn();
    });
    $assert($tenantBEventCount === 0, 'Tenant B can access Tenant A form evidence.');
    $afterB = $store->runWith($tenantB, fn (): array => $factory->create(FormWorkspaceService::class)->getWorkspace());
    $assert(count($afterB['forms']) === count($beforeB['forms']), 'Tenant B can see Tenant A form configuration.');
    $assert(count(array_filter($afterB['forms'], static fn (array $form): bool => $form['id'] === $id)) === 0, 'Tenant B can access Tenant A form.');

    $createdFixtures = $store->runWith($tenantA, function () use ($recordServices, $entityManager, $id): array {
        $createdLead = $recordServices->get('Lead')->create(
            (object) ['firstName' => 'Created', 'lastName' => 'Submission'],
            CreateParams::create(),
        );
        $createdLog = $entityManager->getNewEntity('LeadCaptureLogRecord');
        $createdLog->set([
            'leadCaptureId' => $id,
            'targetId' => $createdLead->getId(),
            'targetType' => 'Lead',
            'isCreated' => true,
            'data' => (object) ['firstName' => 'Created', 'lastName' => 'Submission'],
        ]);
        $entityManager->saveEntity($createdLog);

        $matchedLead = $recordServices->get('Lead')->create(
            (object) ['firstName' => 'Matched', 'lastName' => 'Submission'],
            CreateParams::create(),
        );
        $matchedLog = $entityManager->getNewEntity('LeadCaptureLogRecord');
        $matchedLog->set([
            'leadCaptureId' => $id,
            'targetId' => $matchedLead->getId(),
            'targetType' => 'Lead',
            'isCreated' => false,
            'data' => (object) ['firstName' => 'Matched', 'lastName' => 'Submission'],
        ]);
        $entityManager->saveEntity($matchedLog);

        return [
            'createdLeadId' => $createdLead->getId(),
            'createdLogId' => $createdLog->getId(),
            'matchedLeadId' => $matchedLead->getId(),
            'matchedLogId' => $matchedLog->getId(),
        ];
    });
    $tenantBDeleteBlocked = false;
    try {
        $store->runWith($tenantB, fn (): array => $factory->create(FormWorkspaceService::class)->deleteSubmission($createdFixtures['createdLogId']));
    } catch (NotFound) {
        $tenantBDeleteBlocked = true;
    }
    $assert($tenantBDeleteBlocked, 'Tenant B can delete Tenant A form submissions.');

    $createdDeletion = $store->runWith($tenantA, fn (): array => $factory->create(FormWorkspaceService::class)->deleteSubmission($createdFixtures['createdLogId']));
    $assert($createdDeletion['deletedLead'] === true, 'Deleting a submission-created Lead did not report the Lead soft deletion.');
    $deletedState = $pdo->prepare('SELECT deleted FROM lead WHERE id=? AND tenant_id=? AND service_id=?');
    $deletedState->execute([$createdFixtures['createdLeadId'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $deletedState->fetchColumn() === 1, 'Deleting a submission did not move its created Lead to the recycle bin.');

    $matchedDeletion = $store->runWith($tenantA, fn (): array => $factory->create(FormWorkspaceService::class)->deleteSubmission($createdFixtures['matchedLogId']));
    $assert($matchedDeletion['deletedLead'] === false, 'Deleting a matched submission incorrectly reported a Lead deletion.');
    $deletedState->execute([$createdFixtures['matchedLeadId'], $tenantA->tenantId, $tenantA->serviceId]);
    $assert((int) $deletedState->fetchColumn() === 0, 'Deleting a matched submission removed an existing Lead.');

    $transactionManager->rollback();
} catch (Throwable $e) {
    if ($transactionManager->isStarted()) $transactionManager->rollback();
    throw $e;
}

echo "Tenant Forms workspace tests passed.\n";
