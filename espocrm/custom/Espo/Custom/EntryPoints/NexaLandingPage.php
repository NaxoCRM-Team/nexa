<?php
namespace Espo\Custom\EntryPoints;
use Espo\Core\Api\{Request, Response};
use Espo\Core\EntryPoint\EntryPoint;
use Espo\Core\EntryPoint\Traits\NoAuth;
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\LandingPage\LandingPageRenderer;
final class NexaLandingPage implements EntryPoint { use NoAuth; public function __construct(private LandingPageRenderer $renderer) {} public function run(Request $request, Response $response): void { $key=(string)$request->getQueryParam('key'); $slug=(string)$request->getQueryParam('slug'); if(!$key||!$slug) throw new BadRequest('Landing page address is incomplete.'); $response->setHeader('Content-Type','text/html; charset=UTF-8')->setHeader('Cache-Control','public, max-age=60')->setHeader('Content-Security-Policy',"default-src 'self'; img-src 'self' data:; script-src 'self'; style-src 'unsafe-inline'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'none'; form-action 'self'")->setHeader('Referrer-Policy','strict-origin-when-cross-origin')->setHeader('X-Content-Type-Options','nosniff')->setHeader('Permissions-Policy','camera=(), microphone=(), geolocation=()')->writeBody($this->renderer->render($key,$slug)); } }
