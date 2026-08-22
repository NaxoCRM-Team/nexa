<?php

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\FileStorage\Storages\EspoUploadDir;
use Espo\Entities\Attachment;
use Espo\ORM\EntityManager;

$root = dirname(__DIR__, 2);
chdir($root . DIRECTORY_SEPARATOR . 'espocrm');
require_once 'bootstrap.php';

$application = new Application();
$container = $application->getContainer();
$factory = $container->getByClass(InjectableFactory::class);
$entityManager = $container->getByClass(EntityManager::class);
$fileStorageManager = $factory->create(FileStorageManager::class);
$tenantContextStore = $container->getByClass(TenantContextStore::class);

// Deliberately NOT using PlatformExecutionGateway/runAsPlatform here - found
// the hard way that attachments fetched under platform mode don't hydrate
// tenant_id onto the entity, and the ambient TenantContextStore also has no
// tenant to report in that mode. Both of CloudflareR2's tenant-resolution
// paths silently return nothing, which sent every migrated file to an
// "unscoped" bucket instead of its real tenant's - a real incident during
// this script's own first run, fixed by scripts/dev/fix-misfiled-r2-objects
// (a one-off, not kept in this repo). Iterating one real tenant context at a
// time, like ScheduledJobProvisioner does, keeps that always correct.
$pdo = $entityManager->getPDO();

$tenants = $pdo->query(
    "SELECT DISTINCT t.id, t.slug, t.display_name " .
    "FROM nexa_tenant t " .
    "JOIN attachment a ON a.tenant_id = t.id " .
    "WHERE a.storage = 'EspoUploadDir' OR a.storage IS NULL OR a.storage = ''"
)->fetchAll(PDO::FETCH_ASSOC);

$moved = 0;
$skipped = 0;
$failed = 0;

foreach ($tenants as $tenant) {
    $context = new TenantContext($tenant['id'], $tenant['slug'], 'r2-storage-migration', $tenant['display_name']);

    $tenantContextStore->runWith($context, function () use ($entityManager, $fileStorageManager, &$moved, &$skipped, &$failed) {
        $attachments = $entityManager
            ->getRDBRepository(Attachment::ENTITY_TYPE)
            ->where([
                'OR' => [
                    ['storage' => EspoUploadDir::NAME],
                    ['storage' => null],
                    ['storage' => ''],
                ],
            ])
            ->find();

        foreach ($attachments as $attachment) {
            try {
                if (!$fileStorageManager->exists($attachment)) {
                    fwrite(STDERR, "[SKIP] {$attachment->getId()} - source file missing on local disk.\n");
                    $skipped++;
                    continue;
                }

                $contents = $fileStorageManager->getContents($attachment);

                $attachment->set('storage', 'CloudflareR2');
                $fileStorageManager->putContents($attachment, $contents);
                $entityManager->saveEntity($attachment, ['skipAll' => true]);

                echo "[MOVED] {$attachment->getId()} ({$attachment->get('name')})\n";
                $moved++;
            } catch (\Throwable $e) {
                fwrite(STDERR, "[FAIL] {$attachment->getId()} - {$e->getMessage()}\n");
                $failed++;
            }
        }
    });
}

echo "\nDone. Moved: {$moved}, skipped: {$skipped}, failed: {$failed}.\n";
echo "Original files were left in place under espocrm/data/upload/ as a rollback safety net.\n";
