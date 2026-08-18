<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Call\CallService;

final class PostInitiate implements Action
{
    public function __construct(private CallService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $contactId = trim((string) ($body->contactId ?? ''));
        $toNumber = trim((string) ($body->toNumber ?? ''));

        if ($contactId === '') {
            throw new BadRequest('contactId is required.');
        }

        return ResponseComposer::json($this->service->initiateCall($contactId, $toNumber))
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
