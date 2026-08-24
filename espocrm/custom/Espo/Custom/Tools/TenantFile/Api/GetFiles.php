<?php

namespace Espo\Custom\Tools\TenantFile\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\TenantFile\TenantImageLibrary;

final class GetFiles implements Action
{
    public function __construct(private TenantImageLibrary $library) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->library->getFilePage(
            trim((string) ($request->getQueryParam('search') ?? '')),
            (int) ($request->getQueryParam('offset') ?? 0),
            (int) ($request->getQueryParam('limit') ?? 12),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
