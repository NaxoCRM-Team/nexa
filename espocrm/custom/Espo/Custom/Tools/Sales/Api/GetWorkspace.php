<?php
namespace Espo\Custom\Tools\Sales\Api;
use Espo\Core\Api\Action; use Espo\Core\Api\Request; use Espo\Core\Api\Response; use Espo\Core\Api\ResponseComposer; use Espo\Custom\Tools\Sales\SalesWorkspaceService;
final class GetWorkspace implements Action
{
    public function __construct(private SalesWorkspaceService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getWorkspace(
            (string) ($request->getQueryParam('range') ?? 'quarter'),
            $this->optional($request->getQueryParam('pipelineId')),
            $this->optional($request->getQueryParam('ownerId')),
            $this->optional($request->getQueryParam('teamId')),
        ))->setHeader('Cache-Control', 'private, no-store');
    }

    private function optional(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
