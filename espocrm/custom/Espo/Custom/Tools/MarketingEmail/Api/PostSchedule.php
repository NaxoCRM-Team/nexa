<?php

namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;

final class PostSchedule implements Action
{
    public function __construct(private MarketingDispatchService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->schedule((string) $request->getRouteParam('id')));
    }
}
