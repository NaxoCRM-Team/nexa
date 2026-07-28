<?php

namespace Espo\Custom\Tools\Dashboard\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Dashboard\TenantDashboardService;

final class GetSummary implements Action
{
    public function __construct(private TenantDashboardService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json(
            $this->service->getSummary((string) ($request->getQueryParam('range') ?? '30d'))
        )->setHeader('Cache-Control', 'private, no-store');
    }
}
