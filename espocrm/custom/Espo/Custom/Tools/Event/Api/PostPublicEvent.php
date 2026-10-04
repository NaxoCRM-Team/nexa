<?php

namespace Espo\Custom\Tools\Event\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Event\PublicEventCollectorService;

final class PostPublicEvent implements Action
{
    public function __construct(private PublicEventCollectorService $service) {}

    public function process(Request $request): Response
    {
        $origin = PublicEventCollectorService::normalizeOrigin((string) $request->getHeader('Origin'));
        $result = $this->service->collect(
            (string) $request->getRouteParam('key'),
            $origin,
            $request->getParsedBody(),
            $request->getHeader('Sec-GPC') === '1',
        );
        return ResponseComposer::json($result)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Access-Control-Allow-Origin', $origin)
            ->setHeader('Vary', 'Origin');
    }
}
