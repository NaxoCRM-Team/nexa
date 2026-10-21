<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Conversation\ConversationService;
final class PostUpdate implements Action
{
    public function __construct(private ConversationService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->update((string) $request->getRouteParam('id'), $request->getParsedBody()))->setHeader('Cache-Control', 'private, no-store');
    }
}
