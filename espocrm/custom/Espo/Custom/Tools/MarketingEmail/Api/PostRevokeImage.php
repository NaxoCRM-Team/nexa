<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingImageService;

final class PostRevokeImage implements Action
{
    public function __construct(private MarketingImageService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->revoke((string) $request->getRouteParam('id')))->setHeader('Cache-Control', 'private, no-store');
    }
}
