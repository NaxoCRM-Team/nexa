<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$application = file_get_contents($root . '/espocrm/application/Espo/Core/Application.php');
$installer = file_get_contents($root . '/espocrm/install/core/Installer.php');
$systemData = file_get_contents($root . '/espocrm/application/Espo/Core/Rebuild/Actions/AddSystemData.php');
$migrationScript = file_get_contents($root . '/scripts/dev/apply-shared-schema.ps1');
$nativeSetup = file_get_contents($root . '/scripts/dev/setup-native-windows.ps1');
$nativeInstaller = file_get_contents($root . '/scripts/dev/install-native-application.php');
$browserInstaller = file_get_contents($root . '/espocrm/install/entry.php');

foreach ([$application, $installer, $systemData, $migrationScript, $nativeSetup, $nativeInstaller, $browserInstaller] as $source) {
    if (!is_string($source)) {
        throw new RuntimeException('Unable to read installation bootstrap sources.');
    }
}

$installGate = strpos($application, 'if (!$this->isInstalled())');
$hostResolution = strpos($application, '->resolveHost($host)');

if ($installGate === false || $hostResolution === false || $installGate > $hostResolution) {
    throw new RuntimeException('Application runners must bypass tenant resolution before installation.');
}

$boundaries = [
    'rebuild' => 'runAsInstallationTenant',
    'createUser' => 'runAsInstallationPlatform',
    'setSuccess' => 'runAsInstallationPlatform',
];

foreach ($boundaries as $operation => $boundary) {
    $pattern = '/function ' . preg_quote($operation, '/') . '\b.*?(?=\n    (?:public|private|protected) function |\z)/s';
    $matched = preg_match($pattern, $installer, $matches) === 1;
    $body = $matched ? $matches[0] : '';

    if (!$matched || !str_contains($body, $boundary)) {
        throw new RuntimeException("Installer operation {$operation} must use {$boundary}.");
    }
}

if (!str_contains($systemData, "run('Rebuild global system data'")) {
    throw new RuntimeException('Global system data must use the audited platform gateway.');
}

if (!str_contains($migrationScript, '[switch] $InitializeBaseSchema') ||
    !str_contains($migrationScript, 'Base-schema initialization requires an empty database') ||
    !str_contains($migrationScript, "'nexa.local', 'local', 'verified'")) {
    throw new RuntimeException('The local setup command must safely initialize an empty base schema.');
}

foreach ([
    'database creation' => 'CREATE DATABASE IF NOT EXISTS',
    'base schema' => 'initialize-local-database.ps1',
    'forward migrations' => 'apply-shared-schema.ps1',
    'application installer' => 'install-native-application.php',
    'demo tenants and verification' => 'complete-local-setup.ps1',
    'HTTP login verification' => '/login',
] as $requirement => $marker) {
    if (!str_contains($nativeSetup, $marker)) {
        throw new RuntimeException('Native setup is missing ' . $requirement . '.');
    }
}

foreach (['DatabaseHost', 'DatabasePort', 'SiteUrl'] as $environmentBackedParameter) {
    if (!str_contains($nativeSetup, "PSBoundParameters.ContainsKey('{$environmentBackedParameter}')")) {
        throw new RuntimeException("Native setup must preserve {$environmentBackedParameter} from .env when it is not explicitly overridden.");
    }
}

foreach (['saveData', 'rebuild', 'createUser', 'setSuccess'] as $operation) {
    if (!str_contains($nativeInstaller, '->' . $operation . '(')) {
        throw new RuntimeException('Native installer must call Installer::' . $operation . '.');
    }
}

if (!str_contains($nativeInstaller, "['isInstalled'] ?? false")) {
    throw new RuntimeException('Native installer must be idempotent after installation.');
}

if (!str_contains($browserInstaller, 'if ($installer->isInstalled())') ||
    str_contains($browserInstaller, 'isInstalled() &&')) {
    throw new RuntimeException('The browser installer must be unreachable after native installation.');
}

echo "Installation bootstrap tests passed.\n";
