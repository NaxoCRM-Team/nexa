<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Automation\WorkflowService;

final class PostWebhookReview implements Action
{
    public function __construct(private WorkflowService $service) {}
    public function process(Request $request): Response
    {
        $input=$request->getParsedBody();
        if(!$input instanceof \stdClass || array_diff(array_keys((array)$input),['outcome','evidence']) || !is_string($input->outcome ?? null) || !is_string($input->evidence ?? null))throw new BadRequest('Provide a delivery outcome and review evidence.');
        return ResponseComposer::json($this->service->resolveWebhook((string)$request->getRouteParam('id'),(string)$request->getRouteParam('runId'),$input->outcome,$input->evidence));
    }
}
