<?php

namespace Espo\Custom\Tools\Event\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Event\TrackingSourceService;

final class PostTrackingSourceRotateKey implements Action
{
    public function __construct(private TrackingSourceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->rotateKey((string) $request->getRouteParam('id')));
    }
}
