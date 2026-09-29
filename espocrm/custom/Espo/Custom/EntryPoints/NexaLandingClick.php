<?php
namespace Espo\Custom\EntryPoints;
use Espo\Core\Api\{Request, Response};
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\LandingPage\LandingPageService;
final class NexaLandingClick implements EntryPoint { use NoAuth; public function __construct(private LandingPageService $service) {} public function run(Request $request,Response $response): void { $key=(string)$request->getQueryParam('key');$slug=(string)$request->getQueryParam('slug');$target=(string)$request->getQueryParam('target');$to=(string)$request->getQueryParam('to'); if(!((str_starts_with($to,'/')&&!str_starts_with($to,'//'))||preg_match('#^https?://#i',$to)===1&&filter_var($to,FILTER_VALIDATE_URL))) throw new BadRequest('Invalid destination.'); $this->service->verifyClickDestination($key,$slug,$target,$to,(string)$request->getQueryParam('sig')); $this->service->recordEvent($key,$slug,'click',$target,$_SERVER['HTTP_REFERER']??null,$_SERVER['HTTP_USER_AGENT']??null); $response->setStatus(302)->setHeader('Location',$to)->setHeader('Cache-Control','no-store')->setHeader('Referrer-Policy','strict-origin-when-cross-origin')->setHeader('X-Content-Type-Options','nosniff'); } }
