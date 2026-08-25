<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class DeleteDefinition implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { $this->service->archive((string)$request->getRouteParam('kind'),(string)$request->getRouteParam('id')); return ResponseComposer::json(['success'=>true])->setHeader('Cache-Control','private, no-store'); } }
