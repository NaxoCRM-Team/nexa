<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingImageService;

final class PostDesignerImage implements Action
{
    public function __construct(private MarketingImageService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->publish($request->getParsedBody()))->setHeader('Cache-Control', 'private, no-store');
    }
}
