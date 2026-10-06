<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Custom\Tools\LandingPage\LandingPageService;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
use Espo\Custom\Tools\TenantFile\TenantImageLibrary;

$assert = static function (bool $value, string $message): void {if (!$value) throw new RuntimeException($message);};
$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $factory = $container->getByClass(InjectableFactory::class);
$store = $container->getByClass(TenantContextStore::class); $services = $container->getByClass(ServiceContainer::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'marketing-image-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'marketing-image-test');
$tx = $em->getTransactionManager(); $tx->start(); $file = null;
try {
    $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $uploaded = $store->runWith($a, fn () => $factory->create(TenantImageLibrary::class)->uploadFile('designer.png', 'image/png', $encoded));
    $file = $store->runWith($a, fn () => $em->getEntityById('Attachment', $uploaded['id']));
    $document = $store->runWith($a, fn () => $services->get('Document')->create((object) ['name' => 'Designer image', 'fileId' => $uploaded['id'], 'publishDate' => gmdate('Y-m-d')], CreateParams::create()));
    $assetId = 'designer-' . bin2hex(random_bytes(12));
    $insert = $em->getPDO()->prepare('INSERT INTO nexa_asset_profile (id,tenant_id,service_id,current_attachment_id,document_id,display_name,access_scope) VALUES (?,?,?,?,?,?,?)');
    $insert->execute([$assetId, $a->tenantId, $a->serviceId, $uploaded['id'], $document->getId(), 'Designer image', 'public']);
    $input = (object) ['name' => 'Designer test', 'slug' => 'designer-test-' . bin2hex(random_bytes(4)), 'locale' => 'en', 'primaryColor' => '#087d71', 'backgroundColor' => '#ffffff', 'textColor' => '#172f35',
        'blocks' => [(object) ['id' => 'image', 'type' => 'image', 'assetId' => $assetId]]];
    $page = $store->runWith($a, fn () => $factory->create(LandingPageService::class)->save($input));
    $images = fn (TenantContext $context) => $store->runWith($context, fn () => $factory->create(MarketingEmailWorkspaceService::class)->designerImages());
    $has = fn (array $result) => in_array($assetId, array_column($result['images'], 'id'), true);
    $assert(!$has($images($a)), 'Draft landing images must not become publicly selectable.');
    $store->runWith($a, fn () => $factory->create(LandingPageService::class)->publish($page['id']));
    $assert($has($images($a)), 'Published tenant image must be selectable.');
    $assert(!$has($images($b)), 'Another tenant must not see the image.');
    $otherService = new TenantContext($a->tenantId, $a->slug, 'marketing-image-test', '', '20000000-0000-4000-8000-000000000099');
    $assert(!$has($images($otherService)), 'Another service must not see the image.');
    $preview = $store->runWith($a, fn () => $factory->create(MarketingEmailWorkspaceService::class)->designerImages($assetId));
    $assert($preview['preview'] === 'data:image/png;base64,' . $encoded && str_contains($preview['url'], 'entryPoint=NexaLandingAsset'), 'Preview must use authenticated bytes and existing published URL.');
    $blocked = false;
    try {$store->runWith($b, fn () => $factory->create(MarketingEmailWorkspaceService::class)->designerImages($assetId));}
    catch (\Espo\Core\Exceptions\NotFound) {$blocked = true;}
    $assert($blocked, 'Cross-tenant image selection must fail.');
    $update = $em->getPDO()->prepare('UPDATE nexa_asset_profile SET access_scope=? WHERE id=?');
    $update->execute(['internal', $assetId]);
    $assert(!$has($images($a)), 'Internal images must not be offered.');
    $blocked = false;
    try {$store->runWith($a, fn () => $factory->create(MarketingEmailWorkspaceService::class)->designerImages($assetId));}
    catch (\Espo\Core\Exceptions\NotFound) {$blocked = true;}
    $assert($blocked, 'Changing an image to Internal must revoke preview selection.');
    $update->execute(['public', $assetId]);
    $em->getPDO()->prepare("UPDATE nexa_asset_profile SET status='archived' WHERE id=?")->execute([$assetId]);
    $assert(!$has($images($a)), 'Archived images must not be offered.');
    $em->getPDO()->prepare("UPDATE nexa_asset_profile SET status='active' WHERE id=?")->execute([$assetId]);
    $store->runWith($a, fn () => $factory->create(LandingPageService::class)->archive($page['id']));
    $assert(!$has($images($a)), 'An archived landing page must not supply public email images.');
    echo "Tenant marketing designer image tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n");
    throw $error;
} finally {
    try {if ($file) $store->runWith($a, fn () => $factory->create(\Espo\Core\FileStorage\Manager::class)->unlink($file));}
    finally {$tx->rollback();}
}
