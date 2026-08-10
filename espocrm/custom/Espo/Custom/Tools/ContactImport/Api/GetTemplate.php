<?php

namespace Espo\Custom\Tools\ContactImport\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\ContactImport\ContactImportService;

class GetTemplate implements Action
{
    public function __construct(private ContactImportService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::empty()
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="nexa-contact-import-template.csv"')
            ->writeBody($this->service->getTemplate());
    }
}
