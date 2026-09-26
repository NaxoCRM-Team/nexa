<?php
namespace Espo\Custom\Tools\Consent\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Consent\ConsentService;
final class PostPurpose implements Action { public function __construct(private ConsentService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->savePurpose($request->getParsedBody())); } }
