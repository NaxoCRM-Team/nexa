<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Call\CallService;

final class PostCallerIdStart implements Action
{
    public function __construct(private CallService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $phoneNumber = $body->phoneNumber ?? null;

        if (!is_string($phoneNumber) || trim($phoneNumber) === '') {
            throw new BadRequest('phoneNumber is required.');
        }

        $result = $this->service->startCallerIdVerification($phoneNumber);

        return ResponseComposer::json($result)
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
