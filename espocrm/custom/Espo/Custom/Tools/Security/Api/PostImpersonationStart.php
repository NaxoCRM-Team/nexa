<?php
namespace Espo\Custom\Tools\Security\Api;
use Espo\Core\Api\Action;use Espo\Core\Api\Request;use Espo\Core\Api\Response;use Espo\Core\Api\ResponseComposer;use Espo\Custom\Tools\Security\ImpersonationService;
final class PostImpersonationStart implements Action{public function __construct(private ImpersonationService $service){}public function process(Request $request):Response{return ResponseComposer::json($this->service->start((string)$request->getRouteParam('id')))->setHeader('Cache-Control','no-store');}}
