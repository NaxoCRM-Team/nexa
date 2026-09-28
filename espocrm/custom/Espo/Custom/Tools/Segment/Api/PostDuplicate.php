<?php

namespace Espo\Custom\Tools\Segment\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

final class PostDuplicate implements Action
{
    public function __construct(private SegmentWorkspaceService $service) {}
    public function process(Request $request): Response { return ResponseComposer::json($this->service->duplicate((string) $request->getRouteParam('id'))); }
}
