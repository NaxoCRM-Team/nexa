<?php

namespace Espo\Custom\Tools\Auth;

use Espo\Core\Utils\Config;

/**
 * Publishes only providers whose switch and required credentials are present.
 */
final class AuthProviderRegistry
{
    private const PROVIDERS = [
        'google' => ['label' => 'Google', 'icon' => 'google'],
        'microsoft' => ['label' => 'Microsoft', 'icon' => 'microsoft'],
    ];

    public function __construct(private Config $config)
    {}

    /** @return list<array{key:string,label:string,icon:string,startUrl:string}> */
    public function getPublicProviders(): array
    {
        $configured = (array) $this->config->get('nexaPublicAuthProviders', []);
        foreach (['google', 'microsoft'] as $provider) {
            $environmentKey = 'NEXA_AUTH_' . strtoupper($provider) . '_ENABLED';
            if (getenv($environmentKey) !== false) {
                $configured[$provider] = filter_var(getenv($environmentKey), FILTER_VALIDATE_BOOL) === true;
            }
        }

        foreach (['google', 'microsoft'] as $provider) {
            $prefix = 'NEXA_AUTH_' . strtoupper($provider);
            $clientId = trim((string) getenv($prefix . '_CLIENT_ID'));
            $secret = trim((string) getenv($prefix . '_CLIENT_SECRET'));

            if ($provider === 'google') {
                $clientId = $clientId ?: trim((string) $this->config->get('oidcClientId', ''));
                $secret = $secret ?: trim((string) $this->config->get('oidcClientSecret', ''));
            } else {
                $clientId = $clientId ?: trim((string) $this->config->get('nexaMicrosoftClientId', ''));
                $secret = $secret ?: trim((string) $this->config->get('nexaMicrosoftClientSecret', ''));
            }

            $configured[$provider] = ($configured[$provider] ?? false) === true &&
                $clientId !== '' && $secret !== '';
        }

        $providers = self::normalize($configured);

        foreach ($providers as &$provider) {
            $callbackUrl = $this->callbackUrl($provider['key']);
            $startUrl = preg_replace('~/callback/?$~', '/start', $callbackUrl);

            // Local OAuth callbacks often use localhost while the application
            // is opened through a friendly host. Starting on the callback
            // origin keeps the browser-bound state cookie on the same host.
            if (is_string($startUrl) && filter_var($startUrl, FILTER_VALIDATE_URL)) {
                $provider['startUrl'] = $startUrl;
            }
        }
        unset($provider);

        return $providers;
    }

    private function callbackUrl(string $provider): string
    {
        $key = 'NEXA_AUTH_' . strtoupper($provider) . '_REDIRECT_URI';
        $configured = trim((string) getenv($key));

        if ($configured !== '') {
            return $configured;
        }

        return trim((string) $this->config->get(
            $provider === 'google' ? 'nexaGoogleRedirectUri' : 'nexaMicrosoftRedirectUri',
            ''
        ));
    }

    /**
     * @param array<string, mixed> $configured
     * @return list<array{key:string,label:string,icon:string,startUrl:string}>
     */
    public static function normalize(array $configured): array
    {
        $result = [];

        foreach (self::PROVIDERS as $key => $definition) {
            if (($configured[$key] ?? false) !== true) {
                continue;
            }

            $result[] = [
                'key' => $key,
                'label' => $definition['label'],
                'icon' => $definition['icon'],
                'startUrl' => 'api/v1/Nexa/auth/provider/' . $key . '/start',
            ];
        }

        return $result;
    }
}
