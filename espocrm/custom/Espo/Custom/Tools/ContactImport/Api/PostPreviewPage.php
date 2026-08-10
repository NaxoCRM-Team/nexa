<?php

namespace Espo\Custom\Tools\ContactImport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\ContactImport\ContactImportService;

class PostPreviewPage implements Action
{
    public function __construct(private ContactImportService $service) {}

    public function process(Request $request): Response
    {
        $data = $request->getParsedBody();
        $attachmentId = trim((string) ($data->attachmentId ?? ''));

        if ($attachmentId === '') {
            throw new BadRequest('No validated Contact import file was supplied.');
        }

        return ResponseComposer::json($this->service->getPreviewPage(
            $attachmentId,
            (int) ($data->page ?? 1),
            (int) ($data->pageSize ?? 20)
        ));
    }
}
