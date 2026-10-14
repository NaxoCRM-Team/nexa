<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Automation\WorkflowService;

final class GetWorkflow implements Action
{
    public function __construct(private WorkflowService $service) {}
    public function process(Request $request): Response {return ResponseComposer::json($this->service->detail((string) $request->getRouteParam('id')))->setHeader('Cache-Control', 'private, no-store');}
}
