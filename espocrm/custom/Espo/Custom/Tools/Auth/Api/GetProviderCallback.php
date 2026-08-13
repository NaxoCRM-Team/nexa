<?php

namespace Espo\Custom\Tools\Auth\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Auth\SocialAuthService;

final class GetProviderCallback implements Action
{
    public function __construct(private SocialAuthService $service) {}

    public function process(Request $request): Response
    {
        $provider = (string) $request->getRouteParam('provider');
        $state = (string) $request->getQueryParam('state');
        $cookieState = (string) $request->getCookieParam('nexa_oauth_state');
        $url = $cookieState !== '' && hash_equals($cookieState, $state)
            ? $this->service->callback($provider, $state, (string) $request->getQueryParam('code'))
            : $this->service->failureRedirectUrl('invalid_oauth_state', $provider);
        $cookiePath = $this->service->callbackCookiePath($provider);

        return ResponseComposer::empty()
            ->setStatus(302)
            ->setHeader('Location', $url)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Set-Cookie', "nexa_oauth_state=; Max-Age=0; Path={$cookiePath}; HttpOnly; SameSite=Lax");
    }
}
