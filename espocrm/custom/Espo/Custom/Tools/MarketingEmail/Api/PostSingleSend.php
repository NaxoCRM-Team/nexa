<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;

final class PostSingleSend implements Action
{
    public function __construct(private MarketingDispatchService $service) {}

    public function process(Request $request): Response
    {
        $input = $request->getParsedBody();
        if (!is_object($input) || !is_string($input->contactId ?? null) || array_diff(array_keys(get_object_vars($input)), ['contactId'])) {
            throw new BadRequest('Provide only contactId; recipient, sender and content overrides are not allowed.');
        }
        return ResponseComposer::json($this->service->singleSend((string) $request->getRouteParam('id'), $input->contactId));
    }
}
