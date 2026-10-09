<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Automation\WorkflowService;

final class PostCancel implements Action
{
    public function __construct(private WorkflowService $service) {}
    public function process(Request $request): Response
    {
        if ((array) $request->getParsedBody()) throw new BadRequest('Cancellation does not accept record overrides.');
        return ResponseComposer::json($this->service->cancel((string) $request->getRouteParam('id'), (string) $request->getRouteParam('runId')));
    }
}
