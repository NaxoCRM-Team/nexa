<?php

namespace Espo\Custom\Tools\Customization\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Customization\CustomizationService;

final class DeleteLink implements Action
{
    public function __construct(private CustomizationService $service) {}

    public function process(Request $request): Response
    {
        $this->service->unlink((string) $request->getRouteParam('id'));

        return ResponseComposer::json(['success' => true])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
