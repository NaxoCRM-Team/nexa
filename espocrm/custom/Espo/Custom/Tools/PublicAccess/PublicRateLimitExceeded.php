<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\PublicAccess;

use Espo\Core\Exceptions\BadRequest;

final class PublicRateLimitExceeded extends BadRequest
{
    protected $code = 429;
}
