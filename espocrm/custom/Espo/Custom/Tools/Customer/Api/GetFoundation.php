<?php

namespace Espo\Custom\Tools\Customer\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Customer\CustomerFoundationQueryService;

final class GetFoundation implements Action
{
    public function __construct(private CustomerFoundationQueryService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getSnapshot(
            (string) $request->getRouteParam('entityType'),
            (string) $request->getRouteParam('id'),
            (int) ($request->getQueryParam('timelineLimit') ?? 50),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
