<?php

namespace Espo\Custom\Tools\Currency\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Currency\TenantCurrencyService;

final class PutSettings implements Action
{
    public function __construct(private TenantCurrencyService $service)
    {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json(
            $this->service->save($request->getParsedBody())
        );
    }
}
