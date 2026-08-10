<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Espo\Core\Application;
use Espo\Core\InjectableFactory;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Tools\Address\CountryDefaultsPopulator;

/**
 * Rebuilds Espo's searchable country catalogue from its tracked ISO country
 * resource. The native populator replaces the catalogue atomically and clears
 * the related application cache, so this command is safe to rerun during setup.
 */
try {
    $application = new Application();
    $factory = $application->getContainer()->getByClass(InjectableFactory::class);
    $platform = $application->getContainer()->getByClass(PlatformExecutionGateway::class);
    $populator = $factory->create(CountryDefaultsPopulator::class);
    $platform->run('Populate the shared address-country catalogue.', static function () use ($populator): void {
        $populator->populate();
    });

    fwrite(STDOUT, "Address country catalogue populated.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
