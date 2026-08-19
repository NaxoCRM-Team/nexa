<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Call\CallService;

final class PostUserShareSettings implements Action
{
    public function __construct(private CallService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $userShareMinutes = $body->userShareMinutes ?? null;

        if (!is_numeric($userShareMinutes)) {
            throw new BadRequest('userShareMinutes is required.');
        }

        $this->service->updateUserShareMinutes((int) $userShareMinutes);

        return ResponseComposer::json(['success' => true])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
