<?php
namespace Espo\Custom\Tools\Asset\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Asset\AssetWorkspaceService;
final class GetWorkspace implements Action { public function __construct(private AssetWorkspaceService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->getWorkspace((object) ['search'=>$request->getQueryParam('search'),'status'=>$request->getQueryParam('status'),'type'=>$request->getQueryParam('type'),'offset'=>$request->getQueryParam('offset'),'limit'=>$request->getQueryParam('limit'),'orderBy'=>$request->getQueryParam('orderBy'),'direction'=>$request->getQueryParam('direction')]))->setHeader('Cache-Control','private, no-store'); } }
