<?php

namespace Espo\Custom\Tools\Call\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Call\CallService;
use Espo\Custom\Tools\Call\TwilioClient;

/**
 * Twilio-originated, no EspoCRM session (runs under the TenantContextMiddleware
 * public-platform allow-list). Authenticity is established purely by
 * X-Twilio-Signature - never trust this request otherwise.
 */
final class PostStatus implements Action
{
    public function __construct(
        private CallService $service,
        private TwilioClient $twilioClient,
    ) {}

    public function process(Request $request): Response
    {
        $params = [];
        parse_str((string) $request->getBodyContents(), $params);

        $signature = $request->getHeader('X-Twilio-Signature') ?? '';
        $url = $this->twilioClient->publicWebhookUrl();

        if (!$this->twilioClient->verifySignature($url, $params, $signature)) {
            return ResponseComposer::empty()->setStatus(403);
        }

        $correlationId = trim((string) ($request->getQueryParam('correlationId') ?? ''));
        $this->service->handleStatusCallback($correlationId, $params);

        return ResponseComposer::empty()->setStatus(204);
    }
}
