<?php

namespace Espo\Custom\Tools\Mail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Mail\MailOAuthService;
use RuntimeException;

final class PostOAuthFinish implements Action
{
    public function __construct(private MailOAuthService $service) {}

    public function process(Request $request): Response
    {
        $provider = (string) $request->getRouteParam('provider');
        $state = trim((string) ($request->getParsedBody()->state ?? ''));

        if ($state === '') {
            throw new BadRequest('state is required.');
        }

        try {
            $result = $this->service->finish($provider, $state);
        } catch (RuntimeException $e) {
            throw new BadRequest($e->getMessage());
        }

        return ResponseComposer::json($result)
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
