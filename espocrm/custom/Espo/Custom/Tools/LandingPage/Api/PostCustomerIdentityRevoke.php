<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\LandingPage\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\LandingPage\CustomerPersonalizationService;
final class PostCustomerIdentityRevoke implements Action
{
    public function __construct(private CustomerPersonalizationService $service){}
    public function process(Request $request):Response{return ResponseComposer::json($this->service->revoke($request->getParsedBody()))->setHeader('Cache-Control','private, no-store');}
}
