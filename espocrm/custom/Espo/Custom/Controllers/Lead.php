<?php

declare(strict_types=1);

namespace Espo\Custom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Lead\LeadConversionService;
use stdClass;

/** Adds Nexa's transactional conversion boundary around the native Lead controller. */
final class Lead extends \Espo\Modules\Crm\Controllers\Lead
{
    public function postActionConvert(Request $request): stdClass
    {
        $data = $request->getParsedBody();
        $leadId = $data->id ?? null;

        if (!is_string($leadId) || trim($leadId) === '') {
            throw new BadRequest('Lead ID is required.');
        }

        return $this->injectableFactory
            ->create(LeadConversionService::class)
            ->convert($leadId, fn (): stdClass => parent::postActionConvert($request));
    }
}
