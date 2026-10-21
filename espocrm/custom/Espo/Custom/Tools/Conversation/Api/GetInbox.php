<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action, Request, Response, ResponseComposer};
use Espo\Custom\Tools\Conversation\ConversationService;
final class GetInbox implements Action
{
    public function __construct(private ConversationService $service) {}
    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->listing(
            (string) $request->getQueryParam('q'), (string) $request->getQueryParam('before'),
            (string) $request->getQueryParam('status'), (string) $request->getQueryParam('owner'),
            (string) $request->getQueryParam('view'),
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
