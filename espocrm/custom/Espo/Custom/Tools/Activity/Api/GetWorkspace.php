<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Activity\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Activity\ActivityWorkspaceService;

final class GetWorkspace implements Action
{
    public function __construct(private ActivityWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getWorkspace(
            $this->optional($request->getQueryParam('from')),
            $this->optional($request->getQueryParam('to')),
            $this->optional($request->getQueryParam('ownerId')),
            $this->optional($request->getQueryParam('type')),
        ))->setHeader('Cache-Control', 'private, no-store');
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
