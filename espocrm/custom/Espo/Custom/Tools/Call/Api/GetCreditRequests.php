<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Call\CreditRequestService;

final class GetCreditRequests implements Action
{
    public function __construct(private CreditRequestService $service) {}

    public function process(Request $request): Response
    {
        $status = trim((string) ($request->getQueryParam('status') ?? 'pending'));

        return ResponseComposer::json(['list' => $this->service->listRequests($status)])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
