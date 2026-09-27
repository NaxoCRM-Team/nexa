<?php
namespace Espo\Custom\EntryPoints;
use Espo\Core\Api\{Request, Response};
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\LandingPage\LandingPageService;
final class NexaLandingClick implements EntryPoint { use NoAuth; public function __construct(private LandingPageService $service) {} public function run(Request $request,Response $response): void { $key=(string)$request->getQueryParam('key');$slug=(string)$request->getQueryParam('slug');$to=(string)$request->getQueryParam('to'); if(!(str_starts_with($to,'/')||filter_var($to,FILTER_VALIDATE_URL))) throw new BadRequest('Invalid destination.'); $this->service->recordEvent($key,$slug,'click',(string)$request->getQueryParam('target'),$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null); $response->setStatus(302)->setHeader('Location',$to)->setHeader('Cache-Control','no-store'); } }
