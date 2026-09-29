<?php
namespace Espo\Custom\Tools\Campaign\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Campaign\CampaignWorkspaceService;
final class PostEnroll implements Action { public function __construct(private CampaignWorkspaceService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->enroll((string) $request->getRouteParam('id'))); } }
