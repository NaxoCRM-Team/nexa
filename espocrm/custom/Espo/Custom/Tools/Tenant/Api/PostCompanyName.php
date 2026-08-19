<?php

namespace Espo\Custom\Tools\Tenant\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Tenant\TenantProfileService;

final class PostCompanyName implements Action
{
    public function __construct(private TenantProfileService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        $displayName = $body->displayName ?? null;

        if (!is_string($displayName) || trim($displayName) === '') {
            throw new BadRequest('displayName is required.');
        }

        $this->service->updateCompanyName($displayName);

        return ResponseComposer::json(['success' => true])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
