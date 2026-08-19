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
    $settings = loadTwilioSettings($environmentPath);

    if ($settings === null) {
        fwrite(STDOUT, "TWILIO_ACCOUNT_SID is empty; Twilio configuration was not changed.\n");
        exit(0);
    }

    $application = new Application();
    $factory = $application->getContainer()->getByClass(InjectableFactory::class);
    $writer = $factory->create(ConfigWriter::class);
    $writer->setMultiple($settings);
    $writer->save();

    fwrite(STDOUT, "Twilio configured (account {$settings['nexaTwilioAccountSid']}).\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Twilio configuration failed: {$e->getMessage()}\n");
    exit(1);
}

/** @return array<string, string>|null */
function loadTwilioSettings(string $environmentPath): ?array
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

    $accountSid = $env['TWILIO_ACCOUNT_SID'] ?? '';
    if ($accountSid === '') {
        return null;
    }

    return [
        'nexaTwilioAccountSid' => $accountSid,
        'nexaTwilioAuthToken' => $env['TWILIO_AUTH_TOKEN'] ?? '',
        'nexaTwilioApiKeySid' => $env['TWILIO_API_KEY_SID'] ?? '',
        'nexaTwilioApiKeySecret' => $env['TWILIO_API_KEY_SECRET'] ?? '',
        'nexaTwilioTwimlAppSid' => $env['TWILIO_TWIML_APP_SID'] ?? '',
        'nexaTwilioPublicBaseUrl' => $env['TWILIO_PUBLIC_BASE_URL'] ?? '',
    ];
}

function selfAbsolutePath(string $path, string $root): string
{
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) || str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return $path;
    }

    return $root . DIRECTORY_SEPARATOR . $path;
}
