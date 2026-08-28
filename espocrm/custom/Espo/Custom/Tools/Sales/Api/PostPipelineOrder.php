<?php
namespace Espo\Custom\Tools\Sales\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Sales\SalesWorkspaceService;
final class PostPipelineOrder implements Action { public function __construct(private SalesWorkspaceService $service) {} public function process(Request $request): Response { $body = $request->getParsedBody(); $this->service->reorderPipelines(is_array($body->ids ?? null) ? $body->ids : []); return ResponseComposer::json(['success' => true]); } }
