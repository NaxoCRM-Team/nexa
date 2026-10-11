<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data, Result};

final class DateTimeComparison implements Helper
{
    public static function canonical(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(Z|[+-]([0-9]{2}):([0-9]{2}))$/D', $value, $parts) ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59 ||
            (isset($parts[8]) && ((int) $parts[8] > 14 || (int) $parts[9] > 59 || ((int) $parts[8] === 14 && (int) $parts[9] !== 0))) || str_ends_with($value, '-00:00')) {
            throw new BadRequest('Choose a valid date and time with seconds and an explicit time zone, such as 2028-02-29T09:00:00Z.');
        }
        $utc = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        if (!preg_match('/^[0-9]{4}-/', $utc) || str_starts_with($utc, '0000-')) throw new BadRequest('The UTC date and time must be within years 0001-9999.');
        return $utc;
    }

    public static function matches(mixed $value, string $operator, mixed $threshold, ?\DateTimeImmutable $now = null): bool
    {
        if (!in_array($operator, ['equals','not_equals','before','after','on_or_before','on_or_after','has_value','no_value', ...DateComparison::RELATIVE_OPERATORS], true)) throw new BadRequest('Unsupported date-time condition.');
        $present = $value !== null && $value !== '';
        // Existing custom-property DATETIME storage is UTC, without an offset suffix.
        $fraction = '000000';
        if (is_string($value) && preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?$/D', $value, $stored)) {
            $value = str_replace(' ', 'T', $stored[1]) . 'Z';
            $fraction = str_pad($stored[2] ?? '', 6, '0');
        }
        $actual = $present ? substr(self::canonical($value), 0, -1) . '.' . $fraction . 'Z' : null;
        if (in_array($operator, DateComparison::RELATIVE_OPERATORS, true)) {
            $days = DateComparison::windowDays($threshold);
            $clock = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
            $edge = $clock->modify(($operator === 'within_next_days' ? '+' : '-') . $days . ' days')->format('Y-m-d\TH:i:s.u\Z');
            $clock = $clock->format('Y-m-d\TH:i:s.u\Z');
            return $present && ($operator === 'within_next_days' ? $actual >= $clock && $actual <= $edge : $actual >= $edge && $actual <= $clock);
        }
        if ($operator === 'has_value') return $present;
        if ($operator === 'no_value') return !$present;
        $expected = substr(self::canonical($threshold), 0, -1) . '.000000Z';
        if (!$present) return false;
        $comparison = strcmp($actual, $expected);
        return match ($operator) {
            'equals' => $comparison === 0, 'not_equals' => $comparison !== 0,
            'before' => $comparison < 0, 'after' => $comparison > 0,
            'on_or_before' => $comparison <= 0, 'on_or_after' => $comparison >= 0,
        };
    }

    public function render(Data $data): Result
    {
        $args = $data->getArgumentList();
        if (count($args) !== 3 || !is_string($args[1])) throw new BadRequest('Date-time email conditions require three arguments.');
        $matched = self::matches(...$args);
        $branch = $matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
