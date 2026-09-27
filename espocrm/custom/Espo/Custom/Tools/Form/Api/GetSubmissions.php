<?php

namespace Espo\Custom\Tools\Form\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Form\FormWorkspaceService;

final class GetSubmissions implements Action
{
    public function __construct(private FormWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getSubmissions(
            (int) ($request->getQueryParam('offset') ?? 0),
            (int) ($request->getQueryParam('limit') ?? 100),
            (string) ($request->getQueryParam('q') ?? ''),
            (string) ($request->getQueryParam('orderBy') ?? 'createdAt'),
            (string) ($request->getQueryParam('direction') ?? 'desc'),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
