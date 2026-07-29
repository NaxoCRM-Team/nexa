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

        return self::normalize($configured);
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
