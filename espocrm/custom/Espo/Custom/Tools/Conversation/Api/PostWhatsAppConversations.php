<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation\Api;
use Espo\Core\Api\{Action,Request,Response,ResponseComposer};
use Espo\Core\InjectableFactory;
use Espo\Custom\Tools\Conversation\MessagingConversationService;
final class PostWhatsAppConversations implements Action
{
    public function __construct(private InjectableFactory $factory) {}
    public function process(Request $request): Response
    {
        $service=$this->factory->createWith(MessagingConversationService::class,['channel'=>'whatsapp']);
        return ResponseComposer::json($service->process($request->getParsedBody()))->setHeader('Cache-Control','private, no-store');
    }
}
