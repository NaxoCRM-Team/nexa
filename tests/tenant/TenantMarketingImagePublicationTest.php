<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\Exceptions\{BadRequest, Forbidden, NotFound};
use Espo\Core\FileStorage\Manager;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\{CreateParams, ServiceContainer};
use Espo\Core\Tenant\{TenantContext, TenantContextStore, TenantResolver};
use Espo\Custom\Tools\MarketingEmail\MarketingImageService;
use Espo\Custom\Tools\TenantFile\TenantImageLibrary;
use Espo\Entities\User;

$assert = static function (bool $value, string $message): void {if (!$value) throw new RuntimeException($message);};
$blocked = static function (callable $call, string $message) use ($assert): void {
    $denied = false; try {$call();} catch (BadRequest|Forbidden|NotFound) {$denied = true;} $assert($denied, $message);
};
$app = new Application(); $app->setupSystemUser(); $container = $app->getContainer();
$em = $container->getByClass(EntityManager::class); $factory = $container->getByClass(InjectableFactory::class);
$store = $container->getByClass(TenantContextStore::class); $services = $container->getByClass(ServiceContainer::class);
$storage = $factory->create(Manager::class); $resolver = $factory->create(TenantResolver::class);
$a = new TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'email-image-publication-test');
$b = new TenantContext('30000000-0000-4000-8000-000000000002', 'isolation-beta', 'email-image-publication-test');
$otherService = new TenantContext($a->tenantId, $a->slug, 'email-image-publication-test', '', '20000000-0000-4000-8000-000000000099');
$assetId = 'email-' . bin2hex(random_bytes(12)); $file = null;
$tx = $em->getTransactionManager(); $tx->start();
try {
    $encoded = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    $uploaded = $store->runWith($a, fn () => $factory->create(TenantImageLibrary::class)->uploadFile('email-image.png', 'image/png', $encoded));
    $file = $store->runWith($a, fn () => $em->getEntityById('Attachment', $uploaded['id']));
    $document = $store->runWith($a, fn () => $services->get('Document')->create((object) ['name'=>'Email image fixture','fileId'=>$uploaded['id'],'publishDate'=>gmdate('Y-m-d')], CreateParams::create()));
    $insert = $em->getPDO()->prepare('INSERT INTO nexa_asset_profile (id,tenant_id,service_id,current_attachment_id,document_id,display_name,access_scope) VALUES (?,?,?,?,?,?,?)');
    $insert->execute([$assetId,$a->tenantId,$a->serviceId,$uploaded['id'],$document->getId(),'Email image fixture','internal']);
    $service = $factory->create(MarketingImageService::class);
    $publish = fn (TenantContext $context, bool $confirm = true) => $store->runWith($context, fn () => $service->publish((object) ['assetId'=>$assetId,'confirmPublicCopy'=>$confirm]));
    $blocked(fn () => $publish($a), 'An Internal asset must not be published.');
    $em->getPDO()->prepare("UPDATE nexa_asset_profile SET access_scope='public' WHERE id=?")->execute([$assetId]);
    $blocked(fn () => $publish($a, false), 'Publication must require explicit confirmation.');
    $blocked(fn () => $publish($b), 'Another tenant must not publish the source image.');
    $blocked(fn () => $publish($otherService), 'Another service must not publish the source image.');
    $user = $container->getByClass(User::class); $originalType = $user->get('type');
    try {$user->set('type','regular'); $blocked(fn () => $publish($a), 'Regular users must not publish public image copies.');}
    finally {$user->set('type',$originalType);}
    $publication = $publish($a);
    $assert(str_contains($publication['url'], 'entryPoint=NexaMarketingImage') && $publication['preview'] === 'data:image/png;base64,' . $encoded, 'Independent hosting must use a stable public URL with validated private previews.');
    $assert($publish($a)['id'] === $publication['id'], 'Repeated publication must reuse an identical active snapshot.');
    parse_str(parse_url($publication['url'], PHP_URL_QUERY), $query); $token = $query['token'];
    $assert($resolver->resolveMarketingImageToken($token)?->tenantId === $a->tenantId, 'The public token must resolve the owning tenant.');
    $assert($resolver->resolveMarketingImageToken('invalid') === null, 'Malformed public tokens must not resolve.');
    $read = fn () => $store->runWith($a, fn () => $service->publicImage($token));
    $assert($read()['bytes'] === base64_decode($encoded), 'Public readers must receive the original immutable bytes.');
    $request = new \Espo\Core\Api\RequestWrapper((new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', $publication['url'])->withQueryParams(['token'=>$token]));
    $response = new \Espo\Core\Api\ResponseWrapper(new \Slim\Psr7\Response());
    $store->runWith($resolver->resolveMarketingImageToken($token), fn () => $factory->create(\Espo\Custom\EntryPoints\NexaMarketingImage::class)->run($request, $response));
    $assert($response->getHeader('Content-Type') === 'image/png' && $response->getHeader('Cache-Control') === 'no-store' && $response->getHeader('X-Content-Type-Options') === 'nosniff' && (string) $response->getBody() === base64_decode($encoded), 'The native public entry point must return only image bytes with safe response headers.');
    $blocked(fn () => $store->runWith($b, fn () => $service->publicImage($token)), 'A mismatched tenant context must not serve an image.');
    $html = '<p>Image</p><img src="' . htmlspecialchars($publication['url'], ENT_QUOTES) . '">';
    $manifest = $store->runWith($a, fn () => $service->manifest($html));
    $assert(count($manifest) === 1 && $manifest[0]['sha256'] === hash('sha256', base64_decode($encoded)), 'Hosted image approval evidence must contain its snapshot checksum.');
    $blocked(fn () => $store->runWith($b, fn () => $service->manifest($html)), 'Hand-written HTML must not bypass tenant scoping.');
    $em->getPDO()->prepare("UPDATE nexa_asset_profile SET status='archived',access_scope='internal' WHERE id=?")->execute([$assetId]);
    $assert($read()['bytes'] === base64_decode($encoded), 'Archiving the original asset must not break independently published emails.');
    $store->runWith($a, fn () => $storage->putContents($file, 'changed source bytes'));
    $assert($read()['bytes'] === base64_decode($encoded), 'Replacing the source bytes must not change a published snapshot.');
    $snapshotQuery = $em->getPDO()->prepare('SELECT attachment_id FROM nexa_marketing_image_publication WHERE public_token=?');
    $snapshotQuery->execute([$token]); $snapshotId = $snapshotQuery->fetchColumn();
    $snapshot = $store->runWith($a, fn () => $em->getEntityById('Attachment', $snapshotId));
    $assert($snapshot->getId() !== $file->getId() && !$snapshot->get('sourceId') && $snapshot->get('field') === 'nexaMarketingImage', 'A publication must own independent native storage bytes, not a mutable source alias.');
    $store->runWith($a, fn () => $storage->putContents($snapshot, 'tampered snapshot'));
    $blocked($read, 'Snapshot byte corruption must be blocked at the public boundary.');
    $blocked(fn () => $store->runWith($a, fn () => $service->manifest($html)), 'Snapshot corruption must block email approval.');
    $store->runWith($a, fn () => $storage->putContents($snapshot, base64_decode($encoded)));
    $limiter = $factory->create(\Espo\Custom\Tools\PublicAccess\PublicRequestLimiter::class);
    $fingerprint = (new ReflectionMethod($limiter, 'fingerprint'))->invoke($limiter, $a->tenantId, $a->serviceId, 'marketing-images');
    $counter = $em->getPDO()->prepare('UPDATE nexa_public_rate_limit SET request_count=?,blocked_until=NULL,window_started_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND service_id=? AND scope_key=? AND fingerprint_hash=?');
    $counter->execute([600,$a->tenantId,$a->serviceId,'marketing-images',$fingerprint]);
    $rateLimited = false;
    try {$read();} catch (\Espo\Custom\Tools\PublicAccess\PublicRateLimitExceeded $error) {$rateLimited = $error->getCode() === 429;}
    $assert($rateLimited, 'The public image surface must enforce its persistent 429 request limit.');
    $counter->execute([0,$a->tenantId,$a->serviceId,'marketing-images',$fingerprint]);
    $blocked(fn () => $store->runWith($b, fn () => $service->revoke($publication['id'])), 'Another tenant must not revoke an image.');
    $store->runWith($a, fn () => $service->revoke($publication['id']));
    $store->runWith($a, fn () => $service->revoke($publication['id']));
    $assert($resolver->resolveMarketingImageToken($token) === null, 'Revoked public tokens must stop resolving.');
    $blocked($read, 'Revocation must stop serving the image.');
    $blocked(fn () => $store->runWith($a, fn () => $service->manifest($html)), 'Revoked images must block subsequent approval and dispatch.');
    $audit = $em->getPDO()->prepare('SELECT created_by_id,revoked_by_id,created_at,revoked_at FROM nexa_marketing_image_publication WHERE public_token=?');
    $audit->execute([$token]); $row = $audit->fetch(PDO::FETCH_ASSOC);
    $assert($row['created_by_id'] === $user->getId() && $row['revoked_by_id'] === $user->getId() && $row['created_at'] && $row['revoked_at'], 'Publication and revocation must record the acting administrator and time.');
    echo "Tenant independent marketing image publication tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); throw $error;
} finally {
    try {
        $query = $em->getPDO()->prepare('SELECT attachment_id FROM nexa_marketing_image_publication WHERE asset_id=? AND tenant_id=? AND service_id=?');
        $query->execute([$assetId,$a->tenantId,$a->serviceId]);
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $id) $store->runWith($a, function () use ($em,$storage,$id) {if ($copy = $em->getEntityById('Attachment', $id)) $storage->unlink($copy);});
        if ($file) $store->runWith($a, fn () => $storage->unlink($file));
    } finally {$tx->rollback();}
}
