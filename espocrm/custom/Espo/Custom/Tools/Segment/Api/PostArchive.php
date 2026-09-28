<?php

namespace Espo\Custom\Tools\Segment\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Segment\SegmentWorkspaceService;

final class PostArchive implements Action
{
    public function __construct(private SegmentWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        $this->service->archive((string) $request->getRouteParam('id'));
        return ResponseComposer::json(['archived' => true]);
    }
}
