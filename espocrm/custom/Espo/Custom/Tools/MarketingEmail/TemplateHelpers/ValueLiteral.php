<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\TemplateHelpers;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Htmlizer\Helper;
use Espo\Core\Htmlizer\Helper\{Data,Result};

/** A bounded literal argument for native helpers, without quote/template interpolation. */
final class ValueLiteral implements Helper
{
    public function render(Data $data): Result
    {
        $encoded=$data->getArgumentList()[0] ?? null;
        if (!is_string($encoded) || strlen($encoded)>1360) throw new BadRequest('Conditional comparison value is invalid.');
        $value=base64_decode($encoded,true);
        if ($value===false || base64_encode($value)!==$encoded || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>255) throw new BadRequest('Conditional comparison value is invalid.');
        return Result::create($value);
    }
}
