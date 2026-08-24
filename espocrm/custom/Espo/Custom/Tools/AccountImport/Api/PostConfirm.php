<?php

namespace Espo\Custom\Tools\AccountImport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\AccountImport\AccountImportService;

final class PostConfirm implements Action
{
    public function __construct(private AccountImportService $service) {}

    public function process(Request $request): Response
    {
        $data = $request->getParsedBody();
        $attachmentId = trim((string) ($data->attachmentId ?? ''));
        if ($attachmentId === '') {
            throw new BadRequest('No validated Account import file was supplied.');
        }

        return ResponseComposer::json($this->service->confirm(
            $attachmentId,
            (int) ($data->rowLimit ?? AccountImportService::DEFAULT_ROW_LIMIT)
        ));
    }
}
