<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
require $root . '/espocrm/bootstrap.php';
use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\MarketingEmail\MarketingPreviewService;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;

$app = new Application(); $app->setupSystemUser();
$container = $app->getContainer();
$em = $container->getByClass(EntityManager::class);
$store = $container->getByClass(TenantContextStore::class);
$factory = $container->getByClass(InjectableFactory::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'preview-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'preview-test');
$assert = static function (bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
$tx = $em->getTransactionManager(); $tx->start();
try {
    $contact = $store->runWith($a, function () use ($em) {
        $contact = $em->getNewEntity('Contact');
        $contact->set(['firstName'=>'Preview','lastName'=>'Customer']); $em->saveEntity($contact); return $contact;
    });
    $input = (object) ['contactId'=>$contact->getId(),'subject'=>'Hello {Contact.firstName}','bodyHtml'=>'<p>Welcome {Contact.firstName} {Contact.lastName}</p>'];
    $count = (int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn();
    $result = $store->runWith($a, fn () => $factory->create(MarketingPreviewService::class)->preview($input));
    $assert($result['subject'] === 'Hello Preview' && str_contains($result['bodyHtml'], 'Preview Customer'), 'Native Contact personalization must render.');
    $assert((int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn() === $count, 'Preview must not queue mail.');
    $parameterInput = (object) ['contactId'=>$contact->getId(),'subject'=>'Reference [[nexa:reference:40]]','bodyHtml'=>'<p>[[nexa:message:255]] {Contact.firstName}</p>','preheader'=>'[[nexa:reference:40]] {Contact.lastName}','parameters'=>(object) ['reference'=>'APPT-42','message'=>'A & B confirmed']];
    $parameterResult = $store->runWith($a,fn ()=>$factory->create(MarketingPreviewService::class)->preview($parameterInput));
    $assert($parameterResult['subject'] === 'Reference APPT-42' && $parameterResult['preheader'] === 'APPT-42 Customer' && str_contains($parameterResult['bodyHtml'],'A &amp; B confirmed Preview'),'Safe parameter values and native Contact personalization must render together in every preview field.');
    foreach ([[],null,(object) ['reference'=>'APPT-42'],(object) ['reference'=>'APPT-42','message'=>'{{firstName}}']] as $invalid) {
        $bad = clone $parameterInput; $bad->parameters = $invalid; $denied = false;
        try {$store->runWith($a,fn ()=>$factory->create(MarketingPreviewService::class)->preview($bad));} catch (BadRequest) {$denied = true;}
        $assert($denied,'Invalid, missing or executable parameter values must not preview.');
    }
    $denied = false;
    try {$store->runWith($b,fn ()=>$factory->create(MarketingPreviewService::class)->preview($parameterInput));} catch (NotFound) {$denied = true;}
    $assert($denied,'Parameterized previews must retain tenant isolation.');
    $assert((int) $em->getPDO()->query('SELECT COUNT(*) FROM email_queue_item')->fetchColumn() === $count,'Parameterized previews must not create queue records.');
    $blocked = false;
    try { $store->runWith($b, fn () => $factory->create(MarketingPreviewService::class)->preview($input)); } catch (NotFound) { $blocked = true; }
    $assert($blocked, 'Another tenant must not preview this Contact.');
    $blocked = false;
    try { $store->runWith($a, fn () => $factory->create(MarketingPreviewService::class)->preview((object) ['contactId'=>$contact->getId(),'bodyHtml'=>str_repeat('x', 2_000_001)])); } catch (BadRequest) { $blocked = true; }
    $assert($blocked, 'Oversized previews must be rejected.');
    echo "Tenant marketing preview tests passed.\n";
} finally { $tx->rollback(); }
