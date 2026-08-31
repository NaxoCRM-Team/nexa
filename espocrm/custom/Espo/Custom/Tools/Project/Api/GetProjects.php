<?php
namespace Espo\Custom\Tools\Project\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Project\ProjectService;
final class GetProjects implements Action { public function __construct(private ProjectService $service) {} public function process(Request $request): Response { return ResponseComposer::json(['list' => $this->service->list()])->setHeader('Cache-Control', 'private, no-store'); } }
