<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Call\CreditRequestService;

final class PostCreditRequestDecide implements Action
{
    public function __construct(private CreditRequestService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $requestId = trim((string) ($body->requestId ?? ''));
        $approve = (bool) ($body->approve ?? false);
        $grantedMinutes = isset($body->grantedMinutes) && is_numeric($body->grantedMinutes)
            ? (int) $body->grantedMinutes
            : null;
        $decisionNote = isset($body->decisionNote) ? (string) $body->decisionNote : null;

        if ($requestId === '') {
            throw new BadRequest('requestId is required.');
        }

        $this->service->decide($requestId, $approve, $grantedMinutes, $decisionNote);

        // Unlike PostStatus (a Twilio webhook, never parsed by this app's own
        // frontend), this endpoint IS consumed by Espo.Ajax, which treats an
        // empty 204 body as a parse failure and routes it to the error
        // handler even on success - always return real JSON here.
        return ResponseComposer::json(['success' => true])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
