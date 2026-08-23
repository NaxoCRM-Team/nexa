<?php

namespace Espo\Custom\Tools\Account\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Account\AccountTimelineService;

final class GetTimeline implements Action
{
    public function __construct(private AccountTimelineService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getPage(
            trim((string) $request->getRouteParam('id')),
            trim((string) ($request->getQueryParam('tab') ?? 'activity')),
            (int) ($request->getQueryParam('offset') ?? 0),
            (int) ($request->getQueryParam('limit') ?? 25),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
