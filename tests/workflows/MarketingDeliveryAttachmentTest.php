<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Custom\Tools\MarketingEmail\Delivery\{DeliveryAttachment, DeliveryRequest};

$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$reject = static function (callable $operation) use ($assert): void {
    $blocked = false;
    try { $operation(); } catch (InvalidArgumentException) { $blocked = true; }
    $assert($blocked, 'An unsafe attachment was accepted.');
};
$file = new DeliveryAttachment('customer-guide.txt', 'text/plain', "Customer guide\nThank you for your interest.\n");
$assert(base64_decode($file->toPostalPayload()['data'], true) === $file->contents, 'Attachment bytes were not preserved.');
$csv = new DeliveryAttachment('prices.csv', 'text/csv', "product,price\nSupport,100\n");
$assert($csv->toPostalPayload()['content_type'] === 'text/csv', 'The CSV MIME type was lost.');
foreach (['../guide.txt', 'folder\\guide.txt', "guide\r\nBcc: victim.txt", '', ' guide.txt', 'guide.exe'] as $name) {
    $reject(fn () => new DeliveryAttachment($name, 'text/plain', 'A customer guide.'));
}
$reject(fn () => new DeliveryAttachment('guide.txt', 'text/plain', ''));
$reject(fn () => new DeliveryAttachment('guide.txt', 'text/plain', str_repeat('a', DeliveryAttachment::MAX_BYTES + 1)));
$reject(fn () => new DeliveryAttachment('guide.png', 'image/png', 'This is not a PNG image.'));
$reject(fn () => new DeliveryAttachment('guide.html', 'text/html', '<script>alert(1)</script>'));
$reject(fn () => new DeliveryAttachment('guide.txt', 'text/plain', "Hidden\0binary content"));
$reject(fn () => new DeliveryAttachment("invalid\xff.txt", 'text/plain', 'A customer guide.'));
$makeRequest = static fn (array $attachments) => new DeliveryRequest('customer@example.test', 'sender@example.test', null, null, 'Guide', '<p>Your guide</p>', 'Your guide', 'queue', attachments: $attachments);
$assert(count($makeRequest([$file, $csv])->attachments) === 2, 'Validated attachments were not retained.');
$reject(fn () => $makeRequest([['name' => 'raw.txt', 'data' => 'unchecked']]));
$reject(fn () => $makeRequest(['key' => $file]));
$reject(fn () => $makeRequest(array_fill(0, 11, $file)));
$large = new DeliveryAttachment('large.txt', 'text/plain', str_repeat("Customer guide.\n", DeliveryAttachment::MAX_BYTES / 16));
$makeRequest([$large, $large]);
$reject(fn () => $makeRequest([$large, $large, $file]));
echo "Marketing delivery attachment validation tests passed.\n";
