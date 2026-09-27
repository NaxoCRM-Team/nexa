<?php

namespace Espo\Custom\Tools\Consent\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Consent\CookieConsentService;

final class GetPublicCookieConfig implements Action
{
    public function __construct(private CookieConsentService $service) {}

    public function process(Request $request): Response
    {
        $region = $request->getHeader('CF-IPCountry')
            ?? $request->getHeader('X-Country-Code')
            ?? $request->getQueryParam('region');
        return ResponseComposer::json($this->service->getPublicConfig((string) $request->getRouteParam('key'), is_string($region) ? $region : null))
            ->setHeader('Cache-Control', 'public, max-age=300')
            ->setHeader('Access-Control-Allow-Origin', '*');
    }
}
