<?php
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$certificate = $root . '/.local/postal/root.crt';
if (!is_readable($certificate)) { fwrite(STDERR, "Run setup-postal-local.ps1 first.\n"); exit(1); }
chdir($root . '/espocrm'); require 'bootstrap.php';
$app = new Espo\Core\Application();
$factory = $app->getContainer()->getByClass(Espo\Core\InjectableFactory::class);
$writer = $factory->create(Espo\Core\Utils\Config\ConfigWriter::class);
$writer->set('nexaPostalLocalCaFile', $certificate); $writer->save();
echo "Local Postal CA configured for loopback Postal requests only; TLS verification stays enabled.\n";
