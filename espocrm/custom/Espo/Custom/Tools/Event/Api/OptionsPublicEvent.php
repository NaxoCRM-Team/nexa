<?php

namespace Espo\Custom\Tools\Event\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Event\PublicEventCollectorService;

final class OptionsPublicEvent implements Action
{
    public function __construct(private PublicEventCollectorService $service) {}

    public function process(Request $request): Response
    {
        $origin = PublicEventCollectorService::normalizeOrigin((string) $request->getHeader('Origin'));
        $this->service->authorize((string) $request->getRouteParam('key'), $origin);
        return ResponseComposer::empty()->setStatus(204)
            ->setHeader('Access-Control-Allow-Origin', $origin)
            ->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->setHeader('Access-Control-Max-Age', '600')
            ->setHeader('Vary', 'Origin');
    }
}
