<?php

namespace Espo\Custom\Tools\Auth\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Auth\SocialAuthService;
use Throwable;

final class GetProviderStart implements Action
{
    public function __construct(private SocialAuthService $service) {}

    public function process(Request $request): Response
    {
        try {
            $provider = (string) $request->getRouteParam('provider');
            $url = $this->service->start($provider, $request->getQueryParams());
            parse_str((string) parse_url($url, PHP_URL_QUERY), $parameters);
            $state = rawurlencode((string) ($parameters['state'] ?? ''));
            $secure = $request->getUri()->getScheme() === 'https' ? '; Secure' : '';
            $cookiePath = $this->service->callbackCookiePath($provider);

            return ResponseComposer::empty()
                ->setStatus(302)
                ->setHeader('Location', $url)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('Set-Cookie', "nexa_oauth_state={$state}; Max-Age=600; Path={$cookiePath}; HttpOnly; SameSite=Lax{$secure}");
        } catch (Throwable $e) {
            return ResponseComposer::empty()->setStatus(302)->setHeader('Location', $this->service->failureRedirectUrl('provider_unavailable'));
        }
    }
}
