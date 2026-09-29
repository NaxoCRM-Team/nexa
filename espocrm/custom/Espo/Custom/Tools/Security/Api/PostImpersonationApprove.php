<?php
namespace Espo\Custom\Tools\Security\Api;
use Espo\Core\Api\Action;use Espo\Core\Api\Request;use Espo\Core\Api\Response;use Espo\Core\Api\ResponseComposer;use Espo\Custom\Tools\Security\ImpersonationService;
final class PostImpersonationApprove implements Action{public function __construct(private ImpersonationService $service){}public function process(Request $request):Response{$this->service->approve((string)$request->getRouteParam('id'));return ResponseComposer::json(['success'=>true])->setHeader('Cache-Control','private, no-store');}}
