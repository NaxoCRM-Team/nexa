<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\Action;use Espo\Core\Api\Request;use Espo\Core\Api\Response;use Espo\Core\Api\ResponseComposer;use Espo\Custom\Tools\MarketingEmail\PostalCallbackService;
final class PostPostalCallback implements Action{public function __construct(private PostalCallbackService $service){}public function process(Request $request):Response{return ResponseComposer::json($this->service->receive((string)$request->getRouteParam('token'),$request->getParsedBody()))->setHeader('Cache-Control','no-store');}}
