<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Conversation\MessengerWebhookService;
final class PublicMessenger implements Action
{
    public function __construct(private MessengerWebhookService $service) {}
    public function process(Request $request): Response
    {
        $id=(string)$request->getRouteParam('id');
        if(strtoupper($request->getMethod())==='GET')return ResponseComposer::empty()->writeBody($this->service->challenge($id,$request->getUri()->getQuery()))->setHeader('Content-Type','text/plain; charset=UTF-8')->setHeader('Cache-Control','no-store');
        if($request->getUri()->getQuery()!==''||count($request->getHeaderAsArray('X-Hub-Signature-256'))!==1||count($request->getHeaderAsArray('Content-Type'))!==1||!preg_match('/^application\/json(?:;\s*charset=utf-8)?$/iD',(string)$request->getHeader('Content-Type')))throw new BadRequest('Invalid Messenger webhook request.');
        $this->service->receive($id,(string)$request->getBodyContents(),(string)$request->getHeader('X-Hub-Signature-256'));
        return ResponseComposer::empty()->writeBody('EVENT_RECEIVED')->setHeader('Content-Type','text/plain; charset=UTF-8')->setHeader('Cache-Control','no-store');
    }
}
