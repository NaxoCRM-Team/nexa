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

    /**
     * The scheme+host(+port) Twilio should call back to - never a path.
     * Defaults to the app's own siteUrl, but can be overridden (e.g. to an
     * ngrok tunnel during local development) without touching the real
     * siteUrl setting. Callers needing the app's install-subfolder prefix
     * (e.g. "/nexa") must add installPathPrefix() separately: the override
     * is documented as a bare domain with no path, while siteUrl usually
     * isn't, so this method normalizes both to the same host-only shape
     * rather than leaving callers to handle two different shapes.
     */
    public function publicBaseUrl(): string
    {
        $url = $this->publicUrlSource();
        $scheme = (string) parse_url($url, PHP_URL_SCHEME);
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if ($scheme === '' || $host === '') {
            return rtrim($url, '/');
        }

        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    /**
     * The app's install-subfolder prefix (e.g. "/nexa"). Always derived from
     * the real siteUrl, never from the TWILIO_PUBLIC_BASE_URL override - that
     * override is documented as (and in practice always is) a bare tunnel
     * domain with no path, so deriving the prefix from it would silently
     * produce an empty prefix and break every webhook URL while a tunnel
     * override is active.
     */
    public function installPathPrefix(): string
    {
        return rtrim((string) parse_url((string) $this->config->get('siteUrl', ''), PHP_URL_PATH), '/');
    }

    private function publicUrlSource(): string
    {
        $override = trim((string) (getenv('TWILIO_PUBLIC_BASE_URL') ?: $this->config->get('nexaTwilioPublicBaseUrl', '')));

        return $override !== '' ? $override : (string) $this->config->get('siteUrl', '');
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
     * Reconstructs the exact public URL Twilio POSTed to, for signature
     * verification. The request's own URI can't be trusted for this: PHP has
     * no visibility into ngrok's (or any reverse proxy's) TLS termination, so
     * the scheme it reports locally is "http" even though Twilio signed the
     * "https" public URL - a permanent mismatch if used directly.
     */
    public function publicWebhookUrl(): string
    {
        return $this->publicBaseUrl() . (string) ($_SERVER['REQUEST_URI'] ?? '');
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

    private const TWILIO_ERROR_ALREADY_VERIFIED = 21450;

    /**
     * Starts Twilio's call-based Caller ID verification: Twilio immediately
     * places an automated call to the number and reads out a code the
     * recipient must enter on their keypad. There is no SMS option on this
     * specific Twilio resource - see OutgoingCallerIds in Twilio's docs.
     *
     * alreadyVerified is true when Twilio already has this exact number on
     * its Verified Caller IDs list from some earlier verification (no call
     * gets placed in that case - nothing left to verify). validationCode is
     * the same code Twilio speaks during the call - surfaced back to our own
     * UI too, since relying solely on the recipient correctly hearing an
     * automated voice read out six digits is fragile in practice.
     *
     * @return array{alreadyVerified: bool, validationCode: ?string}
     */
    public function startCallerIdVerification(string $phoneNumber, string $friendlyName): array
    {
        try {
            $response = $this->request('POST', '/OutgoingCallerIds.json', [
                'PhoneNumber' => $phoneNumber,
                'FriendlyName' => $friendlyName,
            ]);
        } catch (TwilioApiException $e) {
            if ($e->twilioErrorCode === self::TWILIO_ERROR_ALREADY_VERIFIED) {
                return ['alreadyVerified' => true, 'validationCode' => null];
            }

            throw $e;
        }

        $validationCode = is_string($response['validation_code'] ?? null) ? $response['validation_code'] : null;

        return ['alreadyVerified' => false, 'validationCode' => $validationCode];
    }

    /**
     * True once the verification call above has actually been completed
     * (code entered correctly) - Twilio only lists a number here after that.
     */
    public function isCallerIdVerified(string $phoneNumber): bool
    {
        $response = $this->request('GET', '/OutgoingCallerIds.json', ['PhoneNumber' => $phoneNumber]);
        $list = $response['outgoing_caller_ids'] ?? [];

        return is_array($list) && count($list) > 0;
    }

    /** @param array<string, string> $params */
    private function request(string $method, string $path, array $params): array
    {
        $accountSid = $this->accountSid();
        $authToken = $this->env('TWILIO_AUTH_TOKEN', 'nexaTwilioAuthToken');
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $accountSid . $path;

        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERPWD => $accountSid . ':' . $authToken,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $method === 'POST' ? http_build_query($params) : null,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        $data = is_string($body) ? json_decode($body, true) : null;

        if ($error !== '' || !is_array($data) || $status >= 300) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : ($error ?: 'request failed');
            $twilioErrorCode = is_array($data) && is_int($data['code'] ?? null) ? $data['code'] : 0;
            throw new TwilioApiException("Twilio API error ({$status}): {$message}", $twilioErrorCode);
        }

        return $data;
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
