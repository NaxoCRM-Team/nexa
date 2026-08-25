<?php
namespace Espo\Custom\Tools\Customization\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Customization\CustomizationService;
final class GetRecords implements Action { public function __construct(private CustomizationService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->records((string)$request->getRouteParam('entityKey'),(int)($request->getQueryParam('offset')??0),(int)($request->getQueryParam('limit')??50),(string)($request->getQueryParam('q')??'')))->setHeader('Cache-Control','private, no-store'); } }
