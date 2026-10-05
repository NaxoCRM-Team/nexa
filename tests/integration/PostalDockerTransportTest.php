<?php
declare(strict_types=1);

// Opt-in transport test; the isolated Postal stack cannot deliver external mail.
$root = dirname(__DIR__, 2);
$settings = json_decode(file_get_contents($root . '/.local/postal/config/local-credentials.json'), true, 512, JSON_THROW_ON_ERROR);
if ($settings['apiBaseUrl'] !== 'https://localhost:8443' || $settings['sender'] !== 'newsletter@nexa.local.test') {
    throw new RuntimeException('Only the local capture stack is allowed.');
}
chdir($root . '/espocrm');
require 'bootstrap.php';
$app = new Espo\Core\Application();
$app->setupSystemUser();
$container = $app->getContainer();
$em = $container->getByClass(Espo\Core\ORM\EntityManager::class);
$store = $container->getByClass(Espo\Core\Tenant\TenantContextStore::class);
$factory = $container->getByClass(Espo\Core\InjectableFactory::class);
$container->getByClass(Espo\Core\Utils\Config::class)->set('nexaPostalLocalCaFile', $root . '/.local/postal/root.crt');
$context = new Espo\Core\Tenant\TenantContext('30000000-0000-4000-8000-000000000001', 'isolation-alpha', 'postal-docker-transport-test');
$tx = $em->getTransactionManager();
$tx->start();
$subject = 'Nexa Postal acceptance ' . bin2hex(random_bytes(8));
try {
    $result = $store->runWith($context, function () use ($factory, $settings, $subject) {
        $factory->create(Espo\Custom\Tools\MarketingEmail\MarketingDeliveryService::class)->saveProvider((object) [
            'apiBaseUrl' => $settings['apiBaseUrl'], 'apiKey' => $settings['apiKey'],
            'serverName' => 'Local capture', 'monthlySendLimit' => 100,
        ]);
        return $factory->create(Espo\Custom\Tools\MarketingEmail\Delivery\PostalDeliveryProvider::class)->send(
            new Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryRequest(
                recipient: 'acceptance@recipient.local.test', fromAddress: $settings['sender'], fromName: 'Nexa local acceptance',
                replyToAddress: null, subject: $subject, htmlBody: '<p>Real Postal API and captured SMTP delivery.</p>',
                textBody: 'Real Postal API and captured SMTP delivery.', queueItemId: 'postal-local-acceptance'
            )
        );
    });
    if ($result->providerMessageId === '') {
        throw new RuntimeException('Postal did not return a message ID.');
    }
    $captured = false;
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $body = @file_get_contents('http://localhost:8026/api/v1/messages');
        $messages = $body === false ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        foreach ($messages['messages'] ?? [] as $message) {
            if (($message['Subject'] ?? '') === $subject) { $captured = true; }
        }
        if ($captured) { break; }
        sleep(2);
    }
    if (!$captured) {
        throw new RuntimeException('Postal accepted the message but SMTP capture failed.');
    }
    echo "Postal HTTPS adapter and real SMTP capture passed. No external email was sent.\n";
} finally {
    $tx->rollback();
}
