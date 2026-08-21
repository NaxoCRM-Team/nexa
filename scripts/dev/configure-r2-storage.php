<?php

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;

$root = dirname(__DIR__, 2);
$environmentPath = $root . DIRECTORY_SEPARATOR . '.env';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $value = substr($argument, strlen('--env='));
        $environmentPath = selfAbsolutePath($value, $root);
    }
}

chdir($root . DIRECTORY_SEPARATOR . 'espocrm');
require_once 'bootstrap.php';

try {
    $settings = loadR2Settings($environmentPath);

    if ($settings === null) {
        fwrite(STDOUT, "CLOUDFLARE_R2_ACCOUNT_ID is empty; R2 storage configuration was not changed.\n");
        exit(0);
    }

    $application = new Application();
    $factory = $application->getContainer()->getByClass(InjectableFactory::class);
    $writer = $factory->create(ConfigWriter::class);
    $writer->setMultiple($settings);
    $writer->save();

    fwrite(STDOUT, "R2 storage configured (bucket {$settings['cloudflareR2Storage']['bucketName']}). New uploads will route to CloudflareR2.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "R2 storage configuration failed: {$e->getMessage()}\n");
    exit(1);
}

/** @return array<string, mixed>|null */
function loadR2Settings(string $environmentPath): ?array
{
    $env = [];

    if (is_file($environmentPath)) {
        foreach (file($environmentPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }
    }

    $accountId = $env['CLOUDFLARE_R2_ACCOUNT_ID'] ?? '';
    if ($accountId === '') {
        return null;
    }

    return [
        'cloudflareR2Storage' => [
            'accountId' => $accountId,
            'bucketName' => $env['CLOUDFLARE_R2_BUCKET_NAME'] ?? '',
            'credentials' => [
                'key' => $env['CLOUDFLARE_R2_ACCESS_KEY_ID'] ?? '',
                'secret' => $env['CLOUDFLARE_R2_ACCESS_KEY_SECRET'] ?? '',
            ],
        ],
        'defaultFileStorage' => 'CloudflareR2',
    ];
}

function selfAbsolutePath(string $path, string $root): string
{
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}
