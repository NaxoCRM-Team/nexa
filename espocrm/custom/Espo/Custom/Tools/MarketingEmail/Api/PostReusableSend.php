<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\MarketingDispatchService;

final class PostReusableSend implements Action
{
    public function __construct(private MarketingDispatchService $service) {}
    public function process(Request $request): Response
    {
        $input = $request->getParsedBody();
        if (!is_string($input->contactId ?? null) || !is_string($input->requestKey ?? null) || !is_int($input->sourceVersion ?? null) || (property_exists($input,'parameters') && !$input->parameters instanceof \stdClass) || array_diff(array_keys(get_object_vars($input)),['contactId','requestKey','sourceVersion','parameters'])) throw new BadRequest('Provide contactId, requestKey, integer sourceVersion and an optional parameters object.');
        return ResponseComposer::json($this->service->reusableSend((string) $request->getRouteParam('id'),$input->contactId,$input->requestKey,$input->sourceVersion,$input->parameters ?? new \stdClass()));
    }
}
