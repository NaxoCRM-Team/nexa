<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\MarketingEmail\MarketingPreviewService;

final class PostPreview implements Action
{
    public function __construct(private MarketingPreviewService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->preview($request->getParsedBody()))->setHeader('Cache-Control', 'private, no-store');
    }
}
