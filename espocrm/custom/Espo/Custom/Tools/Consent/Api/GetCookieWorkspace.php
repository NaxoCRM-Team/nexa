<?php

namespace Espo\Custom\Tools\Consent\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Consent\CookieConsentService;

final class GetCookieWorkspace implements Action
{
    public function __construct(private CookieConsentService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getWorkspace())->setHeader('Cache-Control', 'private, no-store');
    }
}
