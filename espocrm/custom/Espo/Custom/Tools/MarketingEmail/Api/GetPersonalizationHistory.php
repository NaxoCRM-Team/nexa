<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\MarketingEmail\Api;

use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\MarketingEmail\PersonalizationHistoryService;

final class GetPersonalizationHistory implements Action
{
    public function __construct(private PersonalizationHistoryService $service) {}
    public function process(Request $request): Response
    {
        $input=[];
        foreach(['from','to','source','search','cursor'] as $key)if($request->getQueryParam($key)!==null)$input[$key]=$request->getQueryParam($key);
        return ResponseComposer::json($this->service->history($input))->setHeader('Cache-Control','private, no-store');
    }
}
