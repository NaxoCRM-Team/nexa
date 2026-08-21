<?php

namespace Espo\Custom\Tools\Mail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Mail\MailOAuthService;

final class GetOAuthProviders implements Action
{
    public function __construct(private MailOAuthService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json(['list' => $this->service->getConfiguredProviders()])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
