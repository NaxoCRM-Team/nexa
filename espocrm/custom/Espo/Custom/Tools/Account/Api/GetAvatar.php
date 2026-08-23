<?php

namespace Espo\Custom\Tools\Account\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Account\AccountAvatarService;

final class GetAvatar implements Action
{
    public function __construct(private AccountAvatarService $service) {}

    public function process(Request $request): Response
    {
        $id = trim((string) $request->getRouteParam('id'));

        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw new BadRequest('Invalid account ID.');
        }

        return ResponseComposer::json($this->service->get($id))
            ->setHeader('Cache-Control', 'private, no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff');
    }
}
