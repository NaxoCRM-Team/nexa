<?php

namespace Espo\Custom\Tools\Call;

use Espo\Core\Utils\Config;
use RuntimeException;

/**
 * Thin wrapper around the two pieces of Twilio Voice integration that don't need
 * an SDK: signing Access Tokens for the browser Voice JS SDK, and verifying the
 * X-Twilio-Signature header on inbound webhook requests. Both are short,
 * documented HMAC operations - no composer.json exists in this environment to
 * add Twilio's PHP helper library or a JWT library, so these are hand-rolled
 * rather than risking an unverified dependency-management step.
 */
final class TwilioClient
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(private Config $config) {}

    public function accountSid(): string
    {
        return $this->env('TWILIO_ACCOUNT_SID', 'nexaTwilioAccountSid');
    }

    public function callerNumber(): string
    {
        return $this->env('TWILIO_CALLER_NUMBER', 'nexaTwilioCallerNumber');
    }

    /**
     * The base URL Twilio should call back to. Defaults to the app's own
     * siteUrl, but can be overridden (e.g. to an ngrok tunnel during local
     * development) without touching the real siteUrl setting.
     */
    public function publicBaseUrl(): string
    {
        $override = trim((string) (getenv('TWILIO_PUBLIC_BASE_URL') ?: $this->config->get('nexaTwilioPublicBaseUrl', '')));

        if ($override !== '') {
            return rtrim($override, '/');
        }

        return rtrim((string) $this->config->get('siteUrl', ''), '/');
    }

    /**
     * Builds and HS256-signs a Twilio Access Token carrying a VoiceGrant, for the
     * browser Voice JS SDK to authenticate with. Twilio's documented token shape:
     * header cty "twilio-fpa;v=1", payload grants.voice.outgoing.application_sid.
     *
     * @return array{token: string, expiresAt: string}
     */
    public function generateAccessToken(string $identity): array
    {
        $accountSid = $this->accountSid();
        $apiKeySid = $this->env('TWILIO_API_KEY_SID', 'nexaTwilioApiKeySid');
        $apiKeySecret = $this->env('TWILIO_API_KEY_SECRET', 'nexaTwilioApiKeySecret');
        $twimlAppSid = $this->env('TWILIO_TWIML_APP_SID', 'nexaTwilioTwimlAppSid');

        $issuedAt = time();
        $expiresAt = $issuedAt + self::TOKEN_TTL_SECONDS;

        $header = [
            'typ' => 'JWT',
            'alg' => 'HS256',
            'cty' => 'twilio-fpa;v=1',
        ];
        $payload = [
            'jti' => $apiKeySid . '-' . $issuedAt,
            'iss' => $apiKeySid,
            'sub' => $accountSid,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'grants' => [
                'identity' => $identity,
                'voice' => [
                    'outgoing' => ['application_sid' => $twimlAppSid],
                    'incoming' => ['allow' => false],
                ],
            ],
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];
        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $apiKeySecret, true);
        $segments[] = $this->base64UrlEncode($signature);

        return [
            'token' => implode('.', $segments),
            'expiresAt' => gmdate('c', $expiresAt),
        ];
    }

    /**
     * Verifies Twilio's X-Twilio-Signature header per Twilio's documented
     * algorithm: HMAC-SHA1 of the full callback URL with sorted POST params
     * appended as key+value pairs (no delimiter), keyed by the Auth Token,
     * base64-encoded, compared in constant time.
     *
     * @param array<string, string> $postParams
     */
    public function verifySignature(string $fullUrl, array $postParams, string $signatureHeader): bool
    {
        if ($signatureHeader === '') {
            return false;
        }

        $authToken = $this->env('TWILIO_AUTH_TOKEN', 'nexaTwilioAuthToken');

        ksort($postParams);
        $data = $fullUrl;
        foreach ($postParams as $key => $value) {
            $data .= $key . $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $authToken, true));

        return hash_equals($expected, $signatureHeader);
    }

    private function env(string $envKey, string $configKey): string
    {
        $value = trim((string) (getenv($envKey) ?: $this->config->get($configKey, '')));

        if ($value === '') {
            throw new RuntimeException("Twilio configuration value \"{$envKey}\" is not set.");
        }

        return $value;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
