<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';
use Espo\Custom\Tools\MarketingEmail\Delivery\PostalDeliveryProvider;
use Espo\Custom\Tools\MarketingEmail\Delivery\DeliveryProviderException;

$class = new ReflectionClass(PostalDeliveryProvider::class);
$provider = $class->newInstanceWithoutConstructor();
$method = $class->getMethod('responseIsUncertain');
$cases = [
    [false, 'Connection timed out', 0, null, true],
    [false, 'Response too large', 200, ['status' => 'success'], true],
    [true, '', 200, null, true],
    [true, '', 200, ['status' => 'error'], true],
    [true, '', 503, ['status' => 'error'], true],
    [true, '', 401, ['status' => 'success'], true],
    [true, '', 400, ['status' => 'error'], false],
    [true, '', 401, ['status' => 'error'], false],
    [true, '', 429, ['status' => 'error'], false],
];
foreach ($cases as [$completed, $error, $status, $decoded, $expected]) {
    if ($method->invoke($provider, $completed, $error, $status, $decoded) !== $expected) throw new RuntimeException('Postal submission certainty classification failed.');
}
if ((new DeliveryProviderException('Preflight rejection'))->isSubmissionUncertain()) throw new RuntimeException('Local preflight rejections must remain definite.');
if (!(new DeliveryProviderException('Lost response', submissionUncertain: true))->isSubmissionUncertain()) throw new RuntimeException('Uncertain submission metadata was lost.');
echo "Postal submission certainty tests passed.\n";
