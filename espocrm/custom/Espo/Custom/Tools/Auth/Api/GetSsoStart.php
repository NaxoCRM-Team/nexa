<?php

namespace Espo\Custom\Tools\Auth\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Auth\EnterpriseSsoService;
use Throwable;

final class GetSsoStart implements Action
{
    public function __construct(private EnterpriseSsoService $service) {}

    public function process(Request $request): Response
    {
        try {
            $url = $this->service->start((string) $request->getRouteParam('providerId'), $request->getQueryParams());

            return ResponseComposer::empty()->setStatus(302)->setHeader('Location', $url)->setHeader('Cache-Control', 'no-store');
        } catch (Throwable) {
            return ResponseComposer::empty()->setStatus(302)->setHeader('Location', $this->service->failureRedirectUrl('provider_unavailable'));
        }
    }
}
