<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class GetDefinitions implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { $type=$request->getQueryParam('entityType'); return ResponseComposer::json($this->service->definitions(is_string($type)?$type:null))->setHeader('Cache-Control','private, no-store'); } }
