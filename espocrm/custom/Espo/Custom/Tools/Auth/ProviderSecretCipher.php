<?php

namespace Espo\Custom\Tools\Auth;

use Espo\Core\Utils\Config;
use RuntimeException;

/** Encrypts tenant provider credentials with the deployment-owned master key. */
final class ProviderSecretCipher
{
    private const PREFIX = 'nexa:v1:';

    public function __construct(private Config $config) {}

    public function encrypt(string $secret): string
    {
        return $this->encryptFor($secret, 'legacy', 'legacy', 'provider-secret');
    }

    public function encryptFor(
        string $secret,
        string $tenantId,
        string $serviceId,
        string $purpose,
    ): string {
        $keyId = $this->activeKeyId();
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $this->key($keyId),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $this->aad($tenantId, $serviceId, $purpose),
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Secret could not be encrypted.');
        }

        return self::PREFIX . rawurlencode($keyId) . ':' . base64_encode($nonce . $tag . $ciphertext);
    }

    public function decrypt(?string $encrypted): string
    {
        if (str_starts_with((string) $encrypted, self::PREFIX)) {
            return $this->decryptEnvelope((string) $encrypted, 'legacy', 'legacy', 'provider-secret');
        }

        return $this->decryptLegacy($encrypted);
    }

    public function decryptFor(
        ?string $encrypted,
        string $tenantId,
        string $serviceId,
        string $purpose,
    ): string {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }
        if (!str_starts_with($encrypted, self::PREFIX)) {
            return $this->decryptLegacy($encrypted);
        }

        return $this->decryptEnvelope($encrypted, $tenantId, $serviceId, $purpose);
    }

    public function activeKeyId(): string
    {
        $keyId = trim((string) (getenv('NEXA_SECRET_ACTIVE_KEY_ID') ?: $this->config->get('nexaSecretActiveKeyId', 'legacy')));
        if ($keyId === '' || !preg_match('/^[A-Za-z0-9._-]{1,64}$/', $keyId)) {
            throw new RuntimeException('NEXA_SECRET_ACTIVE_KEY_ID is invalid.');
        }

        return $keyId;
    }

    /** @return array{version:int,keyId:string} */
    public function describe(string $encrypted): array
    {
        if (!str_starts_with($encrypted, self::PREFIX)) {
            return ['version' => 0, 'keyId' => 'legacy'];
        }
        $parts = explode(':', $encrypted, 4);

        return ['version' => 1, 'keyId' => rawurldecode($parts[2] ?? '')];
    }

    private function decryptEnvelope(
        string $encrypted,
        string $tenantId,
        string $serviceId,
        string $purpose,
    ): string {
        $parts = explode(':', $encrypted, 4);
        if (count($parts) !== 4 || $parts[0] !== 'nexa' || $parts[1] !== 'v1') {
            throw new RuntimeException('Secret envelope is malformed.');
        }
        $keyId = rawurldecode($parts[2]);
        $payload = base64_decode($parts[3], true);
        if ($keyId === '' || $payload === false || strlen($payload) <= 28) {
            throw new RuntimeException('Secret envelope is malformed.');
        }
        $plain = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $this->key($keyId),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
            $this->aad($tenantId, $serviceId, $purpose),
        );
        if ($plain === false) {
            throw new RuntimeException('Secret could not be decrypted for this tenant, service and purpose.');
        }

        return $plain;
    }

    private function decryptLegacy(?string $encrypted): string
    {
        if ($encrypted === null || $encrypted === '') {
            return '';
        }

        $payload = base64_decode($encrypted, true);
        if ($payload === false || strlen($payload) <= 28) {
            throw new RuntimeException('Legacy secret is malformed.');
        }

        $plain = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            $this->legacyKey(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16),
        );
        if ($plain === false) {
            throw new RuntimeException('Legacy secret could not be decrypted.');
        }

        return $plain;
    }

    private function aad(string $tenantId, string $serviceId, string $purpose): string
    {
        foreach ([$tenantId, $serviceId, $purpose] as $value) {
            if (trim($value) === '' || str_contains($value, "\0")) {
                throw new RuntimeException('Secret encryption scope is invalid.');
            }
        }

        return "nexa-secret\0{$tenantId}\0{$serviceId}\0{$purpose}";
    }

    private function key(string $keyId): string
    {
        $json = trim((string) (getenv('NEXA_SECRET_KEYS') ?: $this->config->get('nexaSecretKeys', '')));
        $keys = $json !== '' ? json_decode($json, true) : null;
        if (is_array($keys) && isset($keys[$keyId])) {
            return $this->decodeKey((string) $keys[$keyId], "NEXA_SECRET_KEYS[{$keyId}]");
        }
        if ($keyId === 'legacy') {
            return $this->legacyKey();
        }

        throw new RuntimeException("Secret key '{$keyId}' is not available.");
    }

    private function legacyKey(): string
    {
        $encoded = trim((string) (getenv('NEXA_AUTH_SECRET_KEY') ?: $this->config->get('nexaAuthSecretKey', '')));

        return $this->decodeKey($encoded, 'NEXA_AUTH_SECRET_KEY');
    }

    private function decodeKey(string $encoded, string $name): string
    {
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException("{$name} must contain a base64-encoded 32-byte key.");
        }

        return $key;
    }
}
