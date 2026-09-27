<?php
namespace Espo\Custom\Tools\Asset\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Asset\AssetWorkspaceService;
final class PostVersion implements Action { public function __construct(private AssetWorkspaceService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->replace((string)$request->getRouteParam('id'),$request->getParsedBody())); } }
