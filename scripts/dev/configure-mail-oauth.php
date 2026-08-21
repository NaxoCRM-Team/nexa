<?php

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Config\ConfigWriter;

$root = dirname(__DIR__, 2);
$environmentPath = $root . DIRECTORY_SEPARATOR . '.env';

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--env=')) {
        $value = substr($argument, strlen('--env='));
        $isAbsolute = str_starts_with($value, DIRECTORY_SEPARATOR) ||
            (strlen($value) > 2 && ctype_alpha($value[0]) && $value[1] === ':');
        $environmentPath = $isAbsolute ? $value : $root . DIRECTORY_SEPARATOR . $value;
    }
}

if (!is_file($environmentPath)) {
    fwrite(STDERR, 'Environment file not found: ' . $environmentPath . PHP_EOL);
    exit(1);
}

$environment = [];
foreach (file($environmentPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $environment[trim($key)] = trim($value);
}

chdir($root . DIRECTORY_SEPARATOR . 'espocrm');
require_once 'bootstrap.php';

$application = new Application();
$factory = $application->getContainer()->getByClass(InjectableFactory::class);
$writer = $factory->create(ConfigWriter::class);
$writer->setMultiple([
    // Deliberately a separate Google OAuth client from login's oidcClientId -
    // this one requests the sensitive gmail.send scope, kept off the login
    // consent screen for cleaner verification/auditing.
    'nexaMailGoogleClientId' => $environment['NEXA_MAIL_GOOGLE_CLIENT_ID'] ?? '',
    'nexaMailGoogleClientSecret' => $environment['NEXA_MAIL_GOOGLE_CLIENT_SECRET'] ?? '',
    'nexaMailGoogleRedirectUri' => $environment['NEXA_MAIL_GOOGLE_REDIRECT_URI'] ?? '',
    // Microsoft mail-connect reuses the existing login app's client
    // id/secret/tenant (nexaMicrosoftClientId/Secret/TenantId) - only the
    // redirect URI differs, since it's a distinct route in this app.
    'nexaMailMicrosoftRedirectUri' => $environment['NEXA_MAIL_MICROSOFT_REDIRECT_URI'] ?? '',
]);
$writer->save();

fwrite(STDOUT, 'Mail OAuth configuration applied.' . PHP_EOL);
