<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Exceptions\BadRequest;

/** Always returns UTC; callers persist due times rather than sleeping inside a worker. */
final class WorkflowClock
{
    public function delay(DateTimeImmutable $now, int $seconds): DateTimeImmutable
    {
        if ($seconds < 1 || $seconds > 31536000) throw new BadRequest('Wait duration must be between one second and one year.');
        return $now->setTimezone(new DateTimeZone('UTC'))->modify('+' . $seconds . ' seconds');
    }

    public function businessHours(DateTimeImmutable $now, array $settings): DateTimeImmutable
    {
        // Reuse the publication contract so malformed schedules cannot reach the clock.
        $node = $settings + ['id' => 'hours', 'type' => 'business_hours', 'next' => 'end'];
        (new WorkflowDefinition())->validate([
            'schemaVersion' => 1, 'trigger' => ['event' => 'manual', 'reenrollment' => 'never'],
            'entry' => 'hours', 'nodes' => [$node, ['id' => 'end', 'type' => 'end']],
        ]);
        $utc = new DateTimeZone('UTC');
        $local = $now->setTimezone(new DateTimeZone($node['timezone']));
        [$startHour, $startMinute] = array_map('intval', explode(':', $node['start']));
        [$endHour, $endMinute] = array_map('intval', explode(':', $node['end']));
        for ($offset = 0; $offset < 15; $offset++) {
            // Calendar-day arithmetic preserves local working hours through DST transitions.
            $day = $local->setTime(12, 0)->modify('+' . $offset . ' days');
            if (!in_array((int) $day->format('N'), $node['weekdays'], true)) continue;
            $start = $day->setTime($startHour, $startMinute);
            $end = $day->setTime($endHour, $endMinute);
            // A nonexistent DST wall time may normalize beyond the available interval.
            if ($start >= $end || $local >= $end) continue;
            return ($local >= $start ? $local : $start)->setTimezone($utc);
        }
        throw new BadRequest('No valid business-time interval could be found.');
    }
}
