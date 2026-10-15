<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Scoring\ScoringService;
final class PostAdjustment implements Action
{
    public function __construct(private ScoringService $service) {}
    public function process(Request $request):Response {return ResponseComposer::json($this->service->adjust((string)$request->getRouteParam('id'),$request->getParsedBody()));}
}
