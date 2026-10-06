<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
final class PostSubmissionReview implements Action
{
    public function __construct(private MarketingDispatchService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->resolveSubmission((string) $request->getRouteParam('id'), $request->getParsedBody()));
    }
}
