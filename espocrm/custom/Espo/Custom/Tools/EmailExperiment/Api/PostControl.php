<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\EmailExperiment\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\EmailExperiment\EmailExperimentService;
final class PostControl implements Action
{
    public function __construct(private EmailExperimentService $service) {}
    public function process(Request $request):Response{return ResponseComposer::json($this->service->control((string)$request->getRouteParam('id'),$request->getParsedBody()));}
}
