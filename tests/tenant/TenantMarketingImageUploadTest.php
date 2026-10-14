<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Api\RequestWrapper;
use Espo\Core\Application;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\FileStorage\Manager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\{TenantContext, TenantContextStore};
use Espo\Custom\Tools\Asset\Api\PostAsset;
use Espo\Custom\Tools\MarketingEmail\Api\PostDesignerImage;
use Espo\Custom\Tools\MarketingEmail\MarketingImageService;

$assert = static function (bool $value, string $message): void {if (!$value) throw new RuntimeException($message);};
$blocked = static function (callable $call, string $message) use ($assert): void {
    $denied = false; try {$call();} catch (BadRequest|Forbidden|NotFound) {$denied = true;} $assert($denied, $message);
};
$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $factory = $container->getByClass(InjectableFactory::class);
$store = $container->getByClass(TenantContextStore::class); $storage = $factory->create(Manager::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'marketing-image-upload-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'marketing-image-upload-test');
$assetId = null; $pdo = $em->getPDO();
$request = static function (array $body): RequestWrapper {
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/Nexa/assets')
        ->withHeader('Content-Type', 'application/json')
        ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body, JSON_THROW_ON_ERROR)));
    return new RequestWrapper($request);
};
try {
    $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $name = 'Email upload fixture ' . bin2hex(random_bytes(8)) . '.png';
    $input = ['displayName'=>$name,'fileName'=>$name,'mimeType'=>'image/png','data'=>$encoded,'accessScope'=>'public'];
    $blocked(fn () => $store->runWith($a, fn () => $factory->create(PostAsset::class)->process($request(array_replace($input, ['data'=>'not-base64'])))), 'Invalid payloads must be rejected by the native asset upload API.');
    $blocked(fn () => $store->runWith($a, fn () => $factory->create(PostAsset::class)->process($request(array_replace($input, ['fileName'=>'unsafe.svg','data'=>base64_encode('<svg></svg>')])))), 'SVG must not pass native file validation.');
    // Asset uploads use their own committed transaction, so remove only this unique fixture in finally.
    $response = $store->runWith($a, fn () => $factory->create(PostAsset::class)->process($request($input)));
    $assetId = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['id'];
    $select = $pdo->prepare('SELECT * FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=?');
    $select->execute([$assetId,$a->tenantId,$a->serviceId]); $asset = $select->fetch(PDO::FETCH_ASSOC);
    $assert($asset && $asset['access_scope'] === 'public' && $asset['document_id'], 'Upload must create a tenant-scoped Public asset backed by a native Document.');
    $images = $factory->create(MarketingImageService::class);
    $blocked(fn () => $store->runWith($b, fn () => $images->publish((object) ['assetId'=>$assetId,'confirmPublicCopy'=>true])), 'A second tenant must not publish the newly uploaded asset.');
    $blocked(fn () => $store->runWith($a, fn () => $images->publish((object) ['assetId'=>$assetId,'confirmPublicCopy'=>false])), 'Uploading a Public asset must not bypass separate publication confirmation.');
    $response = $store->runWith($a, fn () => $factory->create(PostDesignerImage::class)->process($request(['assetId'=>$assetId,'confirmPublicCopy'=>true])));
    $image = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    $assert($image['assetId'] === $assetId && $image['preview'] === 'data:image/png;base64,' . $encoded, 'The real publication API must return decoded-preview bytes for the uploaded asset.');
    $manifest = $store->runWith($a, fn () => $images->manifest('<img src="' . htmlspecialchars($image['url'], ENT_QUOTES) . '">'));
    $assert(count($manifest) === 1 && $manifest[0]['sha256'] === hash('sha256', base64_decode($encoded)), 'Uploaded image copies must enter the normal approval manifest.');
    $select = $pdo->prepare('SELECT attachment_id FROM nexa_marketing_image_publication WHERE asset_id=? AND tenant_id=? AND service_id=?');
    $select->execute([$assetId,$a->tenantId,$a->serviceId]);
    $assert($select->fetchColumn() !== $asset['current_attachment_id'], 'Upload and publication must retain independent native storage files.');
    echo "Native marketing image upload/publication API tests passed.\n";
} finally {
    if ($assetId) {
        $select = $pdo->prepare('SELECT current_attachment_id,document_id FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=?');
        $select->execute([$assetId,$a->tenantId,$a->serviceId]); $asset = $select->fetch(PDO::FETCH_ASSOC);
        $select = $pdo->prepare('SELECT attachment_id FROM nexa_marketing_image_publication WHERE asset_id=? AND tenant_id=? AND service_id=?');
        $select->execute([$assetId,$a->tenantId,$a->serviceId]); $files = $select->fetchAll(PDO::FETCH_COLUMN);
        if ($asset) $files[] = $asset['current_attachment_id'];
        foreach ($files as $id) $store->runWith($a, function () use ($em,$storage,$id): void {if ($file = $em->getEntityById('Attachment',$id)) $storage->unlink($file);});
        $pdo->prepare('DELETE FROM nexa_marketing_image_publication WHERE asset_id=? AND tenant_id=? AND service_id=?')->execute([$assetId,$a->tenantId,$a->serviceId]);
        $pdo->prepare('DELETE FROM nexa_asset_profile WHERE id=? AND tenant_id=? AND service_id=?')->execute([$assetId,$a->tenantId,$a->serviceId]);
        if ($asset) $pdo->prepare('DELETE FROM document WHERE id=? AND tenant_id=? AND service_id=?')->execute([$asset['document_id'],$a->tenantId,$a->serviceId]);
        foreach ($files as $id) $pdo->prepare('DELETE FROM attachment WHERE id=? AND tenant_id=? AND service_id=?')->execute([$id,$a->tenantId,$a->serviceId]);
    }
}
