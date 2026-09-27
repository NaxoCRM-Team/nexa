<?php
namespace Espo\Custom\Tools\Asset\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Asset\AssetWorkspaceService;
final class PostAsset implements Action { public function __construct(private AssetWorkspaceService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->upload($request->getParsedBody())); } }
