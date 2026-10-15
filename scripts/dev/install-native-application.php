<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$environmentFile = '.env';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $environmentFile = substr($argument, strlen('--env='));
    }
}

$environmentPath = str_starts_with($environmentFile, DIRECTORY_SEPARATOR) ||
    preg_match('/^[A-Za-z]:[\\\\\/]/', $environmentFile) === 1
    ? $environmentFile
    : $root . DIRECTORY_SEPARATOR . $environmentFile;

if (!is_file($environmentPath)) {
    fwrite(STDERR, "Environment file not found: {$environmentPath}\n");
    exit(1);
}

$environment = [];
foreach (file($environmentPath, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^\s*#/', $line) === 1 ||
        preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches) !== 1) {
        continue;
    }

    $environment[$matches[1]] = trim(trim($matches[2]), "\"'");
}

$required = ['DB_NAME', 'DB_PASSWORD', 'ADMIN_USERNAME', 'ADMIN_PASSWORD', 'ESPOCRM_SITE_URL'];
foreach ($required as $name) {
    if (($environment[$name] ?? '') === '' || str_starts_with($environment[$name], 'replace_with_')) {
        fwrite(STDERR, "Set {$name} in the ignored .env before bootstrapping the application.\n");
        exit(1);
    }
}

$database = [
    'host' => $environment['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($environment['DB_PORT'] ?? 3306),
    'dbname' => $environment['DB_NAME'],
    'user' => $environment['DB_USER'] ?? 'espocrm',
    'password' => $environment['DB_PASSWORD'],
    'platform' => 'Mysql',
];

$configPath = $root . '/espocrm/data/config-internal.php';
if (is_file($configPath)) {
    $current = include $configPath;

    if (($current['isInstalled'] ?? false) === true) {
        $currentDatabase = $current['database'] ?? [];
        foreach (['host', 'port', 'dbname', 'user', 'password'] as $key) {
            if ((string) ($currentDatabase[$key] ?? '') !== (string) $database[$key]) {
                fwrite(STDERR, "Installed configuration does not match .env database setting {$key}.\n");
                exit(1);
            }
        }

        echo "Application configuration is already installed and matches .env.\n";
        exit(0);
    }
}

chdir($root . '/espocrm');
$adminEmail = $environment['ADMIN_EMAIL'] ?? $environment['ADMIN_USERNAME'];
if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Set a valid ADMIN_EMAIL in the ignored .env before bootstrapping the application.\n");
    exit(1);
}
require_once 'bootstrap.php';
require_once 'install/core/InstallerConfig.php';
require_once 'install/core/SystemHelper.php';
require_once 'install/core/Installer.php';

$installer = new Installer();

try {
    $saved = $installer->saveData([
        'database' => $database,
        'language' => 'en_US',
        'siteUrl' => rtrim($environment['ESPOCRM_SITE_URL'], '/'),
        'theme' => 'Espo',
    ]);

    if (!$saved) {
        throw new RuntimeException('Application configuration could not be written.');
    }

    // The schema fixture contains no records; rebuild hooks can require the system actor.
    $bootstrap = new \Espo\Core\Application();
    $container = $bootstrap->getContainer();
    $systemActor = $container->getByClass(\Espo\Core\ORM\EntityManager::class)->getNewEntity('User');
    $systemActor->set([
        'id' => $container->getByClass(\Espo\Core\InjectableFactory::class)
            ->create(\Espo\Core\Utils\SystemUser::class)->getId(),
        'userName' => \Espo\Core\Utils\SystemUser::NAME,
        'type' => \Espo\Entities\User::TYPE_SYSTEM,
        'isActive' => true,
    ]);
    $container->set('user', $systemActor);
    $container->getByClass(\Espo\Core\Tenant\TenantContextStore::class)->runWith(
        new \Espo\Core\Tenant\TenantContext(
            \Espo\Core\Tenant\TenantContext::LEGACY_LOCAL_ID,
            'legacy-local',
            'native-install-system-user',
        ),
        fn () => $container->getByClass(\Espo\Core\InjectableFactory::class)
            ->create(\Espo\Core\Rebuild\Actions\AddSystemUser::class)->process(),
    );
    $installer->rebuild();

    if (!$installer->createUser($environment['ADMIN_USERNAME'], $environment['ADMIN_PASSWORD'], $adminEmail)) {
        throw new RuntimeException('Bootstrap administrator could not be created.');
    }

    $installer->savePreferences([
        'language' => 'en_US',
        'timeZone' => $environment['ESPOCRM_TIME_ZONE'] ?? 'Europe/London',
        'dateFormat' => 'DD/MM/YYYY',
        'timeFormat' => 'HH:mm',
        'weekStart' => 1,
        'defaultCurrency' => 'USD',
        'thousandSeparator' => ',',
        'decimalMark' => '.',
    ]);
    $installer->saveConfig([
        'applicationName' => $environment['CRM_NAME'] ?? 'Nexa CRM',
    ]);
    $installer->setSuccess();
} catch (Throwable $exception) {
    fwrite(STDERR, "Native application bootstrap failed: {$exception->getMessage()}\n");
    exit(1);
}

echo "Application configuration, bootstrap administrator and installed marker are ready.\n";
