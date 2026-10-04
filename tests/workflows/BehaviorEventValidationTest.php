<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/espocrm/vendor/autoload.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Event\EventContract;

$assert = static fn (bool $condition, string $message) => $condition ?: throw new RuntimeException($message);
$contract = new EventContract();

$event = $contract->normalize((object) [
    'eventType' => 'page.viewed',
    'source' => 'website.collector',
    'idempotencyKey' => 'page-view-1',
    'visitorKey' => 'raw-browser-value',
    'pageUrl' => 'https://example.test/pricing',
    'consent' => (object) ['analytics' => 'granted'],
]);

$assert($event['eventCategory'] === 'website', 'Page events must use the website category.');
$assert($event['consentCategory'] === 'analytics', 'Page events must require analytics consent.');

$expectBadRequest = static function (object $input, string $message) use ($contract): void {
    try {
        $contract->normalize($input);
    } catch (BadRequest) {
        return;
    }
    throw new RuntimeException($message);
};

$expectBadRequest((object) [
    'eventType' => 'page.viewed',
    'source' => 'website.collector',
    'idempotencyKey' => 'no-consent',
], 'Analytics events must be rejected without consent.');

$expectBadRequest((object) [
    'eventType' => 'made.up',
    'source' => 'api.client',
    'idempotencyKey' => 'unknown-type',
    'consent' => (object) ['analytics' => 'granted'],
], 'Unknown non-custom event names must be rejected.');

$expectBadRequest((object) [
    'eventType' => 'form.submitted',
    'source' => 'forms',
    'idempotencyKey' => 'unsafe-link',
    'contactId' => 'contact-id',
], 'Contact linking must require verified identity evidence.');

$identified = $contract->normalize((object) [
    'eventType' => 'form.submitted',
    'source' => 'forms',
    'idempotencyKey' => 'safe-link',
    'contactId' => 'contact-id',
    'identityEvidence' => (object) [
        'type' => 'form_submission',
        'verified' => true,
        'reference' => 'submission-id',
    ],
]);

$assert($identified['identityMethod'] === 'form_submission', 'Verified identity method was not retained.');
$assert(strlen((string) $identified['identityEvidenceHash']) === 64, 'Identity evidence must be hashed.');

echo "Behavior event validation tests passed.\n";

