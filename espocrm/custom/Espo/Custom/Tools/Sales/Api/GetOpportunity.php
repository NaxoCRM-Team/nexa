<?php
namespace Espo\Custom\Tools\Sales\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Sales\SalesWorkspaceService;
final class GetOpportunity implements Action { public function __construct(private SalesWorkspaceService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->getOpportunity(trim((string) $request->getRouteParam('id'))))->setHeader('Cache-Control', 'private, no-store'); } }
