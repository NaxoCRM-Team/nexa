<?php
namespace Espo\Custom\Tools\Security\Api;
use Espo\Core\Api\Action;use Espo\Core\Api\Request;use Espo\Core\Api\Response;use Espo\Core\Api\ResponseComposer;use Espo\Custom\Tools\Security\ImpersonationService;
final class PostImpersonationRequest implements Action{public function __construct(private ImpersonationService $service){}public function process(Request $request):Response{$body=$request->getParsedBody();return ResponseComposer::json($this->service->request((string)($body->tenantSlug??''),(string)($body->targetUserId??''),(string)($body->reason??''),(int)($body->durationMinutes??30)))->setStatus(201)->setHeader('Cache-Control','private, no-store');}}
