<?php

namespace Espo\Custom\Tools\ContactExport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\ContactExport\ContactExportAuditService;

final class PostAudit implements Action
{
    public function __construct(private ContactExportAuditService $service) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();

        return ResponseComposer::json($this->service->register(
            trim((string) ($body->attachmentId ?? '')),
            trim((string) ($body->source ?? '')),
            (int) ($body->count ?? -1),
            trim((string) ($body->format ?? 'csv')),
            trim((string) ($body->exportName ?? '')),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
