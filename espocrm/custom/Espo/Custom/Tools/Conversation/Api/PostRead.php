<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Conversation\ConversationService;
final class PostRead implements Action
{
    public function __construct(private ConversationService $service) {}
    public function process(Request $request): Response
    {
        $body = $request->getParsedBody();
        if (isset($body->isRead) && !is_bool($body->isRead)) throw new \Espo\Core\Exceptions\BadRequest('Choose a valid read state.');
        return ResponseComposer::json($this->service->markRead((string) $request->getRouteParam('id'), $body->isRead ?? true))->setHeader('Cache-Control', 'private, no-store');
    }
}
