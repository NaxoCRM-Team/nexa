<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail\Delivery;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Security\ManagedSecretService;
use JsonException;

/** Postal transport adapter. Campaign, consent and queue ownership stay outside this class. */
final class PostalDeliveryProvider implements MarketingDeliveryProvider
{
    private const SECRET_PURPOSE = 'marketing-delivery';
    private const SECRET_NAME = 'postal-api-key';
    private const MAX_RESPONSE_BYTES = 1_048_576;

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private ManagedSecretService $secrets,
        private Config $config,
    ) {}

    public function send(DeliveryRequest $request): DeliveryResult
    {
        $context = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            "SELECT api_base_url FROM nexa_marketing_delivery_provider WHERE tenant_id=? AND service_id=? AND provider_key='postal' AND status<>'disabled' LIMIT 1"
        );
        $statement->execute([$context->tenantId, $context->serviceId]);
        $baseUrl = $statement->fetchColumn();
        if (!is_string($baseUrl) || $baseUrl === '') {
            throw new DeliveryProviderException('Postal delivery is not configured for this workspace.');
        }

        $payload = [
            'to' => [$request->recipient],
            'from' => $this->mailbox($request->fromAddress, $request->fromName),
            'subject' => $request->subject,
            'html_body' => $request->htmlBody,
            'plain_body' => $request->textBody,
            'headers' => array_merge($request->headers, ['X-Nexa-Queue-Item' => $request->queueItemId]),
        ];
        if ($request->replyToAddress !== null) {
            $payload['reply_to'] = $request->replyToAddress;
        }

        $response = $this->request(rtrim($baseUrl, '/') . '/api/v1/send/message', $payload);
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $messages = is_array($data['messages'] ?? null) ? $data['messages'] : [];
        // Postal keys message results by recipient address.
        $first = $messages !== [] ? reset($messages) : [];
        $first = is_array($first) ? $first : [];
        $messageId = trim((string) ($data['message_id'] ?? $first['id'] ?? $first['message_id'] ?? ''));
        $token = trim((string) ($first['token'] ?? $data['token'] ?? ''));
        if ($messageId === '') {
            throw new DeliveryProviderException('Postal accepted the request without returning a message ID.');
        }

        return new DeliveryResult($messageId, $token !== '' ? $token : null);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function request(string $url, array $payload): array
    {
        if (!str_starts_with(strtolower($url), 'https://')) {
            throw new DeliveryProviderException('Postal requests require HTTPS.');
        }
        if (!function_exists('curl_init')) {
            throw new DeliveryProviderException('The PHP cURL extension is required for Postal delivery.');
        }

        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw new DeliveryProviderException('The Postal payload could not be encoded.', 0, $e);
        }

        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Server-API-Key: ' . $this->secrets->revealForRuntime(self::SECRET_PURPOSE, self::SECRET_NAME),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $localCa = $this->config->get('nexaPostalLocalCaFile');
        if (in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['localhost', '127.0.0.1'], true) && is_string($localCa) && $localCa !== '') {
            if (!is_readable($localCa)) {
                curl_close($curl);
                throw new DeliveryProviderException('The local Postal CA certificate is unavailable.');
            }
            curl_setopt($curl, CURLOPT_CAINFO, $localCa);
        }
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $decoded = json_decode($body, true);
        if ($ok === false || $error !== '' || !is_array($decoded) || $status < 200 || $status >= 300 || ($decoded['status'] ?? null) !== 'success') {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? 'request failed') : ($error ?: 'invalid response');
            throw new DeliveryProviderException("Postal API error ({$status}): {$message}");
        }

        return $decoded;
    }

    private function mailbox(string $address, ?string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return $address;
        }

        return sprintf('%s <%s>', str_replace(["\r", "\n", '<', '>'], '', $name), $address);
    }
}
