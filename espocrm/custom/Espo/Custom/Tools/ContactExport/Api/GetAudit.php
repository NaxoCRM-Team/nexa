<?php

namespace Espo\Custom\Tools\ContactExport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\ContactExport\ContactExportAuditService;

final class GetAudit implements Action
{
    public function __construct(private ContactExportAuditService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->list())
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
