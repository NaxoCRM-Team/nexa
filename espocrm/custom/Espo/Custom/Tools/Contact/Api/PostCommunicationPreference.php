<?php

namespace Espo\Custom\Tools\Contact\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Contact\ContactLifecycleService;

final class PostCommunicationPreference implements Action
{
    public function __construct(private ContactLifecycleService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();

        return ResponseComposer::json($this->service->setCommunicationPreference(
            is_array($body->ids ?? null) ? $body->ids : [],
            is_array($body->channels ?? null) ? $body->channels : [],
            is_string($body->status ?? null) ? $body->status : '',
            is_string($body->reason ?? null) ? $body->reason : '',
            is_string($body->note ?? null) ? $body->note : null,
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
