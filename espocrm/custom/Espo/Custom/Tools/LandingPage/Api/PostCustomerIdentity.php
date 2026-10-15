<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\LandingPage\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\LandingPage\CustomerPersonalizationService;
final class PostCustomerIdentity implements Action
{
    public function __construct(private CustomerPersonalizationService $service){}
    public function process(Request $request):Response
    {
        $input=$request->getParsedBody();if($request->getHeader('Sec-GPC')==='1')$input->privacyControl=true;
        return ResponseComposer::json($this->service->identify($input))->setHeader('Cache-Control','private, no-store');
    }
}
