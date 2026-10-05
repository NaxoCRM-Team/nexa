<?php
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\MarketingEmail\MarketingEmailWorkspaceService;
final class GetVersions implements Action
{
    public function __construct(private MarketingEmailWorkspaceService $service) {}
    public function process(Request $request): Response
    {
        $version = $request->getRouteParam('version');
        return ResponseComposer::json($this->service->versions((string) $request->getRouteParam('id'), $version === null ? null : (int) $version))->setHeader('Cache-Control', 'private, no-store');
    }
}
