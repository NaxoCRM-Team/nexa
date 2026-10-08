<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\MarketingIntegrationAccess;
final class GetIntegrationGrants implements Action
{
    public function __construct(private MarketingIntegrationAccess $access) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->access->catalog((string) $request->getRouteParam('id')));
    }
}
