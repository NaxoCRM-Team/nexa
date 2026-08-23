<?php

namespace Espo\Custom\Tools\Account\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Account\AccountLifecycleService;

final class GetTrash implements Action
{
    public function __construct(private AccountLifecycleService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getTrash())
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
