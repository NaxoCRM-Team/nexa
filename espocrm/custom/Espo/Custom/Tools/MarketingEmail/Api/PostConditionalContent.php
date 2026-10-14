<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingConditionalContentService;
final class PostConditionalContent implements Action
{
    public function __construct(private MarketingConditionalContentService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->compose($request->getParsedBody()))->setHeader('Cache-Control','private, no-store');
    }
}
