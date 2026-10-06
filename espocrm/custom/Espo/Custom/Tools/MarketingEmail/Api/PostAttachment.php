<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;

final class PostAttachment implements Action
{
    public function __construct(private MarketingEmailWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->uploadAttachment($request->getParsedBody()))
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
