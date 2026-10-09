<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data,Result};

/** Compare the existing DECIMAL(30,8) storage format without float conversion. */
final class DecimalComparison implements Helper
{
    public static function canonical(string $value): string
    {
        if (!preg_match('/^(-?)([0-9]{1,22})(?:\.([0-9]{1,8}))?$/D',$value,$parts)) throw new BadRequest('Enter a decimal with up to 22 whole-number digits and 8 decimal places.');
        $magnitude = str_pad($parts[2],22,'0',STR_PAD_LEFT) . str_pad($parts[3] ?? '',8,'0');
        return ($parts[1] === '-' && trim($magnitude,'0') !== '' ? '-' : '+') . $magnitude;
    }

    public function render(Data $data): Result
    {
        $args = $data->getArgumentList();
        if (count($args) !== 3) throw new BadRequest('Decimal email conditions require three arguments.');
        [$value,$operator,$threshold] = $args;
        if (!in_array($operator,['equals','not_equals','greater_than','at_least','less_than','at_most','has_value','no_value'],true)) throw new BadRequest('Unsupported decimal email condition.');
        $present = is_string($value) && $value !== '';
        $actual = $present ? self::canonical($value) : null;
        if (in_array($operator,['has_value','no_value'],true)) $matched = $operator === 'has_value' ? $present : !$present;
        else {
            if (!is_string($threshold)) throw new BadRequest('Invalid decimal email threshold.');
            $expected = self::canonical($threshold);
            $comparison = 0;
            if ($present) {
                $comparison = $actual[0] !== $expected[0] ? ($actual[0] === '-' ? -1 : 1) : strcmp(substr($actual,1),substr($expected,1)) * ($actual[0] === '-' ? -1 : 1);
            }
            $matched = $present && match ($operator) {
                'equals'=>$comparison === 0,'not_equals'=>$comparison !== 0,'greater_than'=>$comparison > 0,
                'at_least'=>$comparison >= 0,'less_than'=>$comparison < 0,'at_most'=>$comparison <= 0,
            };
        }
        $branch = $matched ? $data->getFunction() : $data->getInverseFunction();
        return Result::createSafeString($branch ? $branch() : '');
    }
}
