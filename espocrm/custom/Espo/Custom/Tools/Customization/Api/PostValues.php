<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class PostValues implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { $body=$request->getParsedBody(); return ResponseComposer::json($this->service->saveValues((string)$request->getRouteParam('entityType'),(string)$request->getRouteParam('id'),is_array($body->values??null)?$body->values:[]))->setHeader('Cache-Control','private, no-store'); } }
