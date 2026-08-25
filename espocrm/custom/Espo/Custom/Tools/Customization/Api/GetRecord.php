<?php

namespace Espo\Custom\Tools\Customization\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Customization\CustomizationService;

final class GetRecord implements Action
{
    public function __construct(private CustomizationService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->recordWorkspace(
            (string) $request->getRouteParam('entityKey'),
            (string) $request->getRouteParam('id'),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
