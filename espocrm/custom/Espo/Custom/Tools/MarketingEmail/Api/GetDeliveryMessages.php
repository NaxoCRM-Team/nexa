<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
final class GetDeliveryMessages implements Action
{
    public function __construct(private MarketingEmailWorkspaceService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->deliveryMessages((string) $request->getRouteParam('id'), (string) ($request->getQueryParam('search') ?? ''), (string) ($request->getQueryParam('status') ?? ''), (string) ($request->getQueryParam('cursor') ?? '')))->setHeader('Cache-Control', 'private, no-store');
    }
}
