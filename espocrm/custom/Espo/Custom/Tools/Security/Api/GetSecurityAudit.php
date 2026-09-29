<?php
namespace Espo\Custom\Tools\Security\Api;
use Espo\Core\Api\Action;use Espo\Core\Api\Request;use Espo\Core\Api\Response;use Espo\Core\Api\ResponseComposer;use Espo\Custom\Tools\Security\SecurityAuditQueryService;
final class GetSecurityAudit implements Action{public function __construct(private SecurityAuditQueryService $service){}public function process(Request $request):Response{return ResponseComposer::json($this->service->list((int)($request->getQueryParam('offset')??0),(int)($request->getQueryParam('maxSize')??100)))->setHeader('Cache-Control','private, no-store');}}
