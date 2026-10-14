<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data,Result};

/** Native block helper: bounded integer comparison only, with no data access or side effects. */
final class NumericComparison implements Helper
{
    public function render(Data $data): Result
    {
        $args = $data->getArgumentList();
        if (count($args) !== 3) throw new BadRequest('Numeric email conditions require three arguments.');
        [$value,$operator,$threshold] = $args;
        if (!in_array($operator,['equals','not_equals','greater_than','at_least','less_than','at_most','has_value','no_value'],true)) throw new BadRequest('Unsupported numeric email condition.');
        $present = is_int($value) && $value >= 0 && $value <= 2147483647;
        if (in_array($operator,['has_value','no_value'],true)) $matched = $operator === 'has_value' ? $present : !$present;
        else {
            if (!is_string($threshold) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D',$threshold) || (float) $threshold > 2147483647) throw new BadRequest('Invalid numeric email threshold.');
            $number = (int) $threshold;
            $matched = $present && match ($operator) {
                'equals'=>$value === $number, 'not_equals'=>$value !== $number,
                'greater_than'=>$value > $number, 'at_least'=>$value >= $number,
                'less_than'=>$value < $number, 'at_most'=>$value <= $number,
            };
        }
        $branch = $matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
