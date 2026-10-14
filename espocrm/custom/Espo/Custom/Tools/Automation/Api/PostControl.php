<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Automation\WorkflowService;

final class PostControl implements Action
{
    public function __construct(private WorkflowService $service) {}
    public function process(Request $request): Response
    {
        $input = $request->getParsedBody();
        if (!is_string($input->status ?? null) || array_diff(array_keys((array) $input), ['status'])) throw new BadRequest('Provide the workflow state.');
        return ResponseComposer::json($this->service->control((string) $request->getRouteParam('id'), $input->status));
    }
}
