<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\Consent\ConsentService;
use Espo\Custom\Tools\MarketingEmail\TransactionalEmailPolicy;

$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $pdo = $em->getPDO();
$factory = $container->getByClass(InjectableFactory::class);
$contexts = $container->getByClass(TenantContextStore::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'transactional-policy-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'transactional-policy-test');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$transactions = $em->getTransactionManager(); $transactions->start();
try {
    $consent = $factory->create(ConsentService::class); $policy = $factory->create(TransactionalEmailPolicy::class);
    $purposes = $contexts->runWith($a, fn () => $consent->getWorkspace())['purposes'];
    $purpose = array_values(array_filter($purposes, static fn ($p) => $p['purposeKey'] === 'customer_service'))[0];
    $email = 'transactional-' . bin2hex(random_bytes(8)) . '@example.test';
    $contact = $contexts->runWith($a, fn () => $container->getByClass(ServiceContainer::class)->get('Contact')->create((object) [
        'firstName'=>'Operational', 'lastName'=>'Acceptance', 'emailAddress'=>$email, 'marketingStatus'=>'Non-Marketing',
    ], CreateParams::create()));
    $decision = (object) ['contactId'=>$contact->getId(), 'purposeId'=>$purpose['id'], 'channel'=>'email',
        'status'=>'not_required', 'legalBasis'=>'PerformanceOfContract', 'source'=>'manual'];
    $blocked = false;
    try { $contexts->runWith($a, fn () => $consent->recordDecision($decision)); } catch (BadRequest) { $blocked = true; }
    $assert($blocked, 'Operational non-consent decisions require explicit evidence.');
    $decision->evidenceNote = 'Synthetic contracted service notification evidence.';
    $contexts->runWith($a, fn () => $consent->recordDecision($decision));
    $assert($policy->recipient($a, $purpose['id'], $contact->getId())['email'] === $email, 'Contract evidence should authorize a non-marketing operational recipient.');
    $user = $container->getByClass(\Espo\Entities\User::class); $type = $user->get('type');
    try {
        $user->set('type', 'regular'); $blocked = false;
        try { $contexts->runWith($a, fn () => $consent->recordDecision($decision)); } catch (Forbidden) { $blocked = true; }
        $assert($blocked, 'Only administrators may authorize operational email without consent.');
    } finally { $user->set('type', $type); }
    $decision->status = 'granted'; $decision->legalBasis = 'FreelyGivenConsent';
    $contexts->runWith($a, fn () => $consent->recordDecision($decision));
    $assert($policy->recipient($a, $purpose['id'], $contact->getId()) !== null, 'Purpose-specific granted consent should qualify.');
    foreach (['denied','withdrawn','not_required'] as $status) {
        $pdo->prepare('UPDATE nexa_consent_state SET status=? WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=?')
            ->execute([$status,$a->tenantId,$a->serviceId,$contact->getId(),$purpose['id']]);
        $assert($policy->recipient($a, $purpose['id'], $contact->getId()) === null, 'Forged, denied or withdrawn states must not qualify.');
    }
    $contexts->runWith($a, fn () => $consent->recordDecision($decision));
    foreach (['expires_at'=>"DATE_SUB(NOW(6),INTERVAL 1 DAY)", 'effective_at'=>"DATE_ADD(NOW(6),INTERVAL 1 DAY)", 'policy_version'=>"'stale-policy'"] as $column=>$value) {
        $pdo->prepare("UPDATE nexa_consent_state SET `$column`=$value WHERE tenant_id=? AND service_id=? AND contact_id=? AND purpose_id=?")
            ->execute([$a->tenantId,$a->serviceId,$contact->getId(),$purpose['id']]);
        $assert($policy->recipient($a, $purpose['id'], $contact->getId()) === null, 'Expired, future and stale-policy decisions must fail closed.');
        $contexts->runWith($a, fn () => $consent->recordDecision($decision));
    }
    foreach (['do_not_contact'=>1,'do_not_contact_channels'=>'email'] as $column=>$value) {
        $pdo->prepare("UPDATE contact SET `$column`=? WHERE id=? AND tenant_id=? AND service_id=?")->execute([$value,$contact->getId(),$a->tenantId,$a->serviceId]);
        $assert($policy->recipient($a, $purpose['id'], $contact->getId()) === null, 'Transactional mode must honor contact restrictions.');
        $pdo->prepare('UPDATE contact SET do_not_contact=0,do_not_contact_channels=NULL WHERE id=? AND tenant_id=? AND service_id=?')->execute([$contact->getId(),$a->tenantId,$a->serviceId]);
    }
    foreach (['invalid','opt_out'] as $column) {
        $pdo->prepare("UPDATE email_address SET `$column`=1 WHERE name=? AND tenant_id=? AND service_id=?")->execute([$email,$a->tenantId,$a->serviceId]);
        $assert($policy->recipient($a, $purpose['id'], $contact->getId()) === null, 'Native invalid addresses and opt-outs must remain enforced.');
        $pdo->prepare("UPDATE email_address SET `$column`=0 WHERE name=? AND tenant_id=? AND service_id=?")->execute([$email,$a->tenantId,$a->serviceId]);
    }
    foreach (['unsubscribe','hard_bounce','complaint','manual','invalid'] as $reason) {
        $pdo->prepare("INSERT INTO nexa_marketing_suppression (id,tenant_id,service_id,email_address,reason,source) VALUES (UUID(),?,?,?,?, 'acceptance')")
            ->execute([$a->tenantId,$a->serviceId,$email,$reason]);
        $assert($policy->recipient($a, $purpose['id'], $contact->getId()) === null, 'Transactional mode must retain every active suppression.');
        $pdo->prepare('DELETE FROM nexa_marketing_suppression WHERE tenant_id=? AND service_id=? AND email_address=?')->execute([$a->tenantId,$a->serviceId,$email]);
    }
    $other = $contexts->runWith($b, fn () => $consent->getWorkspace())['purposes'];
    $other = array_values(array_filter($other, static fn ($p) => $p['purposeKey'] === 'customer_service'))[0];
    $assert($policy->recipient($b, $other['id'], $contact->getId()) === null, 'Another tenant must not access the operational recipient.');
    $blocked = false;
    try { $policy->requirePurpose($b, $purpose['id']); } catch (BadRequest) { $blocked = true; }
    $assert($blocked, 'Operational purpose IDs must retain tenant ownership.');
    $pdo->prepare('UPDATE nexa_consent_purpose SET is_active=0 WHERE id=? AND tenant_id=? AND service_id=?')->execute([$purpose['id'],$a->tenantId,$a->serviceId]);
    $blocked = false;
    try { $policy->recipient($a, $purpose['id'], $contact->getId()); } catch (BadRequest) { $blocked = true; }
    $assert($blocked, 'Revoked purpose configuration must block delivery.');
    echo "Transactional purpose, canonical evidence, suppression and tenant isolation tests passed.\n";
} finally { $transactions->rollback(); }
