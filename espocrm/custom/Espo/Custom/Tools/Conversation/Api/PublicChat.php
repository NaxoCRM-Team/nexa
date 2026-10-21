<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Conversation\PublicChatService;
use Espo\Custom\Tools\Event\PublicEventCollectorService;
use Espo\Core\Exceptions\{BadRequest,Forbidden,NotFound,Conflict};
final class PublicChat implements Action
{
    public function __construct(private PublicChatService $service) {}
    public function process(Request $request): Response
    {
        $origin=PublicEventCollectorService::normalizeOrigin((string)$request->getHeader('Origin'));
        $key=(string)$request->getRouteParam('key');
        // Only approved origins receive CORS headers, including capability revocation errors.
        $this->service->authorize($key,$origin);
        if (strtoupper($request->getMethod())==='OPTIONS') {
            $this->service->authorize($key,$origin);
            $response=ResponseComposer::empty()->setStatus(204)->setHeader('Access-Control-Allow-Methods','POST, OPTIONS')->setHeader('Access-Control-Allow-Headers','Content-Type, X-Nexa-Chat-Token')->setHeader('Access-Control-Max-Age','600');
        } else {
            try{$response=ResponseComposer::json($this->service->process($key,$origin,(string)$request->getHeader('X-Nexa-Chat-Token'),$request->getParsedBody()));}
            catch(BadRequest|Forbidden|NotFound|Conflict $error){$response=ResponseComposer::json(['message'=>'Chat session unavailable.'])->setStatus($error->getCode());}
        }
        return $response->setHeader('Cache-Control','no-store')->setHeader('Access-Control-Allow-Origin',$origin)->setHeader('Vary','Origin');
    }
}
