<?php

namespace Espo\Custom\Tools\Event\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Event\EventRetentionService;

final class GetEventRetention implements Action
{
    public function __construct(private EventRetentionService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->workspace())
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
