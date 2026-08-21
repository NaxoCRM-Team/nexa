<?php

namespace Espo\Custom\Tools\Mail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Mail\MailOAuthService;
use RuntimeException;

/**
 * Authenticated (unlike the login flow's GetProviderStart, which is a
 * noAuth GET redirect): the whole point is knowing WHICH already-logged-in
 * user is connecting a mailbox, which only a live, authenticated request
 * context can tell us. Returns a URL for the frontend to send a popup to,
 * rather than redirecting itself.
 */
final class PostOAuthStart implements Action
{
    public function __construct(private MailOAuthService $service) {}

    public function process(Request $request): Response
    {
        $provider = (string) $request->getRouteParam('provider');

        try {
            $authorizationUrl = $this->service->start($provider);
        } catch (RuntimeException $e) {
            throw new BadRequest($e->getMessage());
        }

        return ResponseComposer::json(['authorizationUrl' => $authorizationUrl])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
