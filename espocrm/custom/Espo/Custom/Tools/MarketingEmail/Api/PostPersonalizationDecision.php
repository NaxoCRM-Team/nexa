<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingPreviewService;

final class PostPersonalizationDecision implements Action
{
    public function __construct(private MarketingPreviewService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->decision($request->getParsedBody()))
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
