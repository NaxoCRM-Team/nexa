<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data, Result};

/** Date properties are calendar dates, not timestamps in the worker's timezone. */
final class DateComparison implements Helper
{
    public const RELATIVE_OPERATORS = ['within_next_days', 'within_last_days'];

    public static function windowDays(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,2})$/D', $value) || (int) $value > 365) throw new BadRequest('Choose a whole-day window from 0 to 365.');
        return (int) $value;
    }

    public static function canonical(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', $value, $parts) || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) throw new BadRequest('Choose a valid calendar date in YYYY-MM-DD format.');
        return $value;
    }

    public static function matches(mixed $value, string $operator, mixed $threshold, ?\DateTimeImmutable $now = null): bool
    {
        if (!in_array($operator, ['equals', 'not_equals', 'before', 'after', 'on_or_before', 'on_or_after', 'has_value', 'no_value', ...self::RELATIVE_OPERATORS], true)) throw new BadRequest('Unsupported date condition.');
        $present = $value !== null && $value !== '';
        $actual = $present ? self::canonical($value) : null;
        if (in_array($operator, self::RELATIVE_OPERATORS, true)) {
            $days = self::windowDays($threshold);
            $today = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
            $edge = $today->modify(($operator === 'within_next_days' ? '+' : '-') . $days . ' days')->format('Y-m-d');
            $today = $today->format('Y-m-d');
            return $present && ($operator === 'within_next_days' ? $actual >= $today && $actual <= $edge : $actual >= $edge && $actual <= $today);
        }
        if ($operator === 'has_value') return $present;
        if ($operator === 'no_value') return !$present;
        $expected = self::canonical($threshold);
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
        if (count($args) !== 3 || !is_string($args[1])) throw new BadRequest('Date email conditions require three arguments.');
        $matched = self::matches(...$args);
        $branch = $matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
