<?php

namespace Espo\Custom\Tools\ContactImport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\ContactImport\ContactImportService;

class PostPreview implements Action
{
    public function __construct(private ContactImportService $service) {}

    public function process(Request $request): Response
    {
        $fileName = rawurldecode($request->getHeader('X-Nexa-File-Name') ?? '');
        $rowLimit = (int) ($request->getQueryParam('rowLimit') ?? ContactImportService::DEFAULT_ROW_LIMIT);

        return ResponseComposer::json(
            $this->service->preview($request->getBodyContents() ?? '', $fileName, $rowLimit)
        );
    }
}
