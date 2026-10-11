<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Automation\WorkflowService;

final class PostEvent implements Action
{
    public function __construct(private WorkflowService $service) {}
    public function process(Request $request): Response
    {
        $input=$request->getParsedBody();
        if(!$input instanceof \stdClass || array_diff(array_keys((array)$input),['contactId','requestKey']) || !is_string($input->contactId ?? null) || !is_string($input->requestKey ?? null))throw new BadRequest('Provide a Contact and a distinct intentional event key.');
        return ResponseComposer::json($this->service->intakeEvent((string)$request->getRouteParam('id'),$input->contactId,$input->requestKey));
    }
}
