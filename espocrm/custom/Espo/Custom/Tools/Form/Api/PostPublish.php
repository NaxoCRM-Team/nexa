<?php

namespace Espo\Custom\Tools\Form\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Form\FormWorkspaceService;

final class PostPublish implements Action
{
    public function __construct(private FormWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->publish((string) $request->getRouteParam('id')));
    }
}
