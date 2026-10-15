<?php
namespace Espo\Custom\Tools\Abm\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Abm\AbmService;
final class PostArchive implements Action{public function __construct(private AbmService $service){}public function process(Request $request):Response{return ResponseComposer::json($this->service->archive((string)$request->getRouteParam('id')));}}
