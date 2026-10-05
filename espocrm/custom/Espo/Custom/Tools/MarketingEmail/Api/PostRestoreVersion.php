<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
final class PostRestoreVersion implements Action
{
    public function __construct(private MarketingEmailWorkspaceService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->restoreVersion((string) $request->getRouteParam('id'), (int) $request->getRouteParam('version')));
    }
}
