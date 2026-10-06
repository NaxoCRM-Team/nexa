<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
final class GetSubmissionReview implements Action
{
    public function __construct(private MarketingDispatchService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->reviewSubmissions((string) $request->getRouteParam('id')))->setHeader('Cache-Control', 'private, no-store');
    }
}
