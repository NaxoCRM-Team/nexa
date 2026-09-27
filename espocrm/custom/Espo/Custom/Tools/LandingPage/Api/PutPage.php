<?php
namespace Espo\Custom\Tools\LandingPage\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\LandingPage\LandingPageService;
final class PutPage implements Action { public function __construct(private LandingPageService $service) {} public function process(Request $request): Response { return ResponseComposer::json($this->service->save($request->getParsedBody(),(string)$request->getRouteParam('id'))); } }
