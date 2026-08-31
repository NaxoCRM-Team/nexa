<?php
namespace Espo\Custom\Tools\CaseService\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\CaseService\CaseSlaService;
final class PostCaseResponse implements Action { public function __construct(private CaseSlaService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->recordResponse((string) $request->getRouteParam('id'))); } }
