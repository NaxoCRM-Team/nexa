<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Scoring\ScoringService;
final class GetHistory implements Action {
    public function __construct(private ScoringService $service) {}
    public function process(Request $request):Response {return ResponseComposer::json($this->service->history((string)$request->getRouteParam('id'),(string)$request->getRouteParam('recordId')));}
}
