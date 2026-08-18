<?php

namespace Espo\Custom\Tools\Contact\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Contact\ContactLifecycleService;

final class PostAssign implements Action
{
    public function __construct(private ContactLifecycleService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();

        return ResponseComposer::json($this->service->assign(
            is_array($body->ids ?? null) ? $body->ids : [],
            is_string($body->assignedUserId ?? null) ? $body->assignedUserId : null,
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
