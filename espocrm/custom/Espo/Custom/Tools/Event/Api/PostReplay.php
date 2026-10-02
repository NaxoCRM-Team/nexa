<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Event\BehaviorEventReplayService;

final class PostReplay implements Action
{
    public function __construct(private BehaviorEventReplayService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json(
            $this->service->request((string) $request->getRouteParam('id'), $request->getParsedBody())
        )->setHeader('Cache-Control', 'private, no-store');
    }
}

