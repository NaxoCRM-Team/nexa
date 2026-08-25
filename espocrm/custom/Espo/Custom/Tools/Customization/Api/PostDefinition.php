<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class PostDefinition implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { $body=$request->getParsedBody(); return ResponseComposer::json($this->service->saveDefinition((string)$request->getRouteParam('kind'),(array)$body))->setHeader('Cache-Control','private, no-store'); } }
