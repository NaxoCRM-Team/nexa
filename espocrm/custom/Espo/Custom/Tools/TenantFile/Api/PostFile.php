<?php

namespace Espo\Custom\Tools\TenantFile\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\TenantFile\TenantImageLibrary;

final class PostFile implements Action
{
    public function __construct(private TenantImageLibrary $library) {}

    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        return ResponseComposer::json($this->library->uploadFile(
            trim((string) ($body->name ?? '')),
            trim((string) ($body->mimeType ?? '')),
            trim((string) ($body->data ?? '')),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
