<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;

use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Conversation\MessagingCallbackService;

final class PublicMessagingCallback implements Action
{
    public function __construct(private MessagingCallbackService $service) {}
    public function process(Request $request): Response
    {
        if ($request->getQueryParams() || count($request->getHeaderAsArray('X-Twilio-Signature')) !== 1 ||
            count($request->getHeaderAsArray('Content-Type')) !== 1) throw new BadRequest('Invalid callback request.');
        $this->service->process((string)$request->getRouteParam('id'),(string)$request->getRouteParam('kind'),
            (string)$request->getBodyContents(),(string)$request->getHeader('Content-Type'),(string)$request->getHeader('X-Twilio-Signature'),$request->getRouteParam('attempt'));
        // Empty TwiML acknowledges inbound delivery without generating an automatic reply.
        return ResponseComposer::empty()->writeBody('<Response/>')->setHeader('Content-Type','text/xml; charset=UTF-8')->setHeader('Cache-Control','no-store');
    }
}
