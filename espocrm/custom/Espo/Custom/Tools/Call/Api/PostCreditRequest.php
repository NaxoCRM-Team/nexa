<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Call\CreditRequestService;

final class PostCreditRequest implements Action
{
    public function __construct(private CreditRequestService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $reason = trim((string) ($body->reason ?? ''));
        $requestedMinutes = $body->requestedMinutes ?? null;

        if (!is_numeric($requestedMinutes)) {
            throw new BadRequest('requestedMinutes is required.');
        }

        return ResponseComposer::json($this->service->submitRequest($reason, (int) $requestedMinutes))
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
