<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class PostRecord implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->saveRecord((string)$request->getRouteParam('entityKey'),(array)$request->getParsedBody()))->setHeader('Cache-Control','private, no-store'); } }
