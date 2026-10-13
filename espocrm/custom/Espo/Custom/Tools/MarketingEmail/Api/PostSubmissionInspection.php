<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;
final class PostSubmissionInspection implements Action
{
    public function __construct(private MarketingDispatchService $service) {}
    public function process(Request $request): Response
    {
        $input = $request->getParsedBody();
        if (!is_string($input->messageId ?? null) || !is_int($input->providerRecordId ?? null) || $input->providerRecordId < 1
            || array_diff(array_keys(get_object_vars($input)),['messageId','providerRecordId'])) throw new BadRequest('Provide a submission ID and positive numeric Postal record ID.');
        return ResponseComposer::json($this->service->inspectSubmission((string) $request->getRouteParam('id'),$input->messageId,$input->providerRecordId));
    }
}
