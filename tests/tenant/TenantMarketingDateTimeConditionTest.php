<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateTimeComparison as Condition;
use Espo\Core\Exceptions\BadRequest;

$checks = 0;
$assert = static function (bool $ok, string $message) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException($message);
};
foreach (['UTC','Europe/London','America/New_York','Asia/Kolkata'] as $zone) {
    date_default_timezone_set($zone);
    $assert(Condition::canonical('2028-02-29T10:30:00+01:30') === '2028-02-29T09:00:00Z', 'Offsets must resolve independently of the worker timezone.');
    foreach (['equals'=>true,'not_equals'=>false,'before'=>false,'after'=>false,'on_or_before'=>true,'on_or_after'=>true,'has_value'=>true,'no_value'=>false] as $operator=>$expected) {
        $assert(Condition::matches('2028-02-29 09:00:00', $operator, '2028-02-29T04:00:00-05:00') === $expected, 'UTC stored values must preserve comparison boundaries.');
        $assert(Condition::matches(null, $operator, '2028-02-29T09:00:00Z') === ($operator === 'no_value'), 'Missing timestamps must not match inequality.');
    }
    $assert(Condition::matches('2028-02-29 09:00:00','before','2028-02-29T09:00:01Z'), 'Seconds must be significant.');
    $assert(Condition::matches('2028-02-29 09:00:00.000000','equals','2028-02-29T09:00:00Z'), 'Native DATETIME(6) zero fractions must compare correctly.');
    $assert(Condition::matches('2028-02-29 09:00:00.000001','after','2028-02-29T09:00:00Z'), 'Stored microseconds must not be rounded into equality.');
    $assert(Condition::matches('2028-03-01 00:00:00','equals','2028-02-29T19:00:00-05:00'), 'Offsets crossing date boundaries must compare as instants.');
}
foreach (['2027-02-29T09:00:00Z','2028-02-30T09:00:00Z','2028-02-29T24:00:00Z','2028-02-29T09:60:00Z','2028-02-29T09:00:60Z','2028-02-29T09:00:00','2028-02-29 09:00:00','2028-02-29','tomorrow','2028-02-29T09:00:00+14:01','2028-02-29T09:00:00+15:00','2028-02-29T09:00:00-00:00','0001-01-01T00:00:00+14:00','9999-12-31T23:59:59-14:00',null,123] as $invalid) {
    $rejected=false;
    try {Condition::canonical($invalid);} catch (BadRequest) {$rejected=true;}
    $assert($rejected, 'Invalid, ambiguous and out-of-range timestamps must be rejected.');
}
echo "Date-time condition contract passed: {$checks} checks.\n";
