<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Custom\Tools\Conversation\ChatService;
final class PostChat implements Action
{
    public function __construct(private ChatService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->process($request->getParsedBody()))->setHeader('Cache-Control','private, no-store');
    }
}
