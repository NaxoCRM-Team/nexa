<?php

namespace Espo\Custom\Tools\Customization\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Customization\CustomizationService;

final class GetRelationshipWorkspace implements Action
{
    public function __construct(private CustomizationService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->relationshipWorkspace(
            (string) $request->getRouteParam('definitionId'),
            (string) $request->getRouteParam('entityType'),
            (string) $request->getRouteParam('entityId'),
            (string) ($request->getQueryParam('q') ?? ''),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
