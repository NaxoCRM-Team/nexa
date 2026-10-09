<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Custom\Tools\Automation\WorkflowClock;
use Espo\Core\Exceptions\BadRequest;

$clock = new WorkflowClock();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$hours = ['timezone' => 'Europe/London', 'weekdays' => [1, 2, 3, 4, 5], 'start' => '09:00', 'end' => '17:00'];
foreach ([
    ['2026-03-27T16:59:59Z', '2026-03-27T16:59:59Z'],
    ['2026-03-27T17:00:00Z', '2026-03-30T08:00:00Z'],
    ['2026-03-29T02:00:00Z', '2026-03-30T08:00:00Z'],
    ['2026-10-23T16:00:00Z', '2026-10-26T09:00:00Z'],
    ['2026-10-25T02:00:00Z', '2026-10-26T09:00:00Z'],
    ['2026-10-26T08:00:00Z', '2026-10-26T09:00:00Z'],
    ['2026-10-26T09:00:00Z', '2026-10-26T09:00:00Z'],
] as [$now, $expected]) {
    $actual = $clock->businessHours(new DateTimeImmutable($now), $hours);
    $assert($actual->getTimestamp() === (new DateTimeImmutable($expected))->getTimestamp(), 'Business hours must honor local weekdays and DST: ' . $now);
    $assert($actual->getTimezone()->getName() === 'UTC', 'Persisted due times must be UTC.');
}
$india = $hours; $india['timezone'] = 'Asia/Kolkata';
$assert($clock->businessHours(new DateTimeImmutable('2026-10-09T02:00:00Z'), $india)->format('Y-m-d H:i:s') === '2026-10-09 03:30:00', 'Fractional-hour time zones must retain local opening time.');
$sunday = ['timezone' => 'Europe/London', 'weekdays' => [7], 'start' => '01:30', 'end' => '03:00'];
$assert($clock->businessHours(new DateTimeImmutable('2026-03-29T00:00:00Z'), $sunday)->format('Y-m-d H:i:s') === '2026-03-29 01:30:00', 'A nonexistent spring-forward wall time must normalize within the valid interval.');
$delay = $clock->delay(new DateTimeImmutable('2026-03-28T12:00:00+00:00'), 86400);
$assert($delay->format('Y-m-d H:i:s') === '2026-03-29 12:00:00', 'An elapsed-day delay must not silently become a 23-hour local day.');
$assert($clock->delay(new DateTimeImmutable('2026-10-09T10:00:00+05:30'), 60)->format('Y-m-d H:i:s') === '2026-10-09 04:31:00', 'Delay results must normalize source offsets to UTC.');
foreach ([0, -1, 31536001] as $seconds) {
    $blocked = false;
    try {$clock->delay(new DateTimeImmutable(), $seconds);} catch (BadRequest) {$blocked = true;}
    $assert($blocked, 'Invalid delays must be rejected.');
}
$bad = $hours; $bad['weekdays'] = [];
$blocked = false;
try {$clock->businessHours(new DateTimeImmutable(), $bad);} catch (BadRequest) {$blocked = true;}
$assert($blocked, 'Invalid schedules must fail rather than looping indefinitely.');
echo "Automation clock contract passed ($checks checks).\n";
