<?php
namespace Espo\Custom\Tools\Sales\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Sales\SalesWorkspaceService;
final class DeleteProduct implements Action { public function __construct(private SalesWorkspaceService $service) {} public function process(Request $request): Response { $this->service->archiveProduct(trim((string) $request->getRouteParam('id'))); return ResponseComposer::json(['success' => true]); } }
