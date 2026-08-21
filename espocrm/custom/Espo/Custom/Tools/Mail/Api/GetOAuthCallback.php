<?php

namespace Espo\Custom\Tools\Mail\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Mail\MailOAuthService;

/**
 * Reached directly by Google/Microsoft with no EspoCRM session (noAuth,
 * allow-listed in TenantContextMiddleware) - see MailOAuthService's
 * docblock for why entity creation is deferred to the authenticated
 * PostOAuthFinish instead of happening here. This just records the
 * exchanged token via handleCallback() and hands the opaque state back to
 * the opener window via postMessage, which is what actually finishes the
 * connection - this page doesn't need to know success or failure itself.
 */
final class GetOAuthCallback implements Action
{
    public function __construct(private MailOAuthService $service) {}

    public function process(Request $request): Response
    {
        $provider = (string) $request->getRouteParam('provider');
        $state = (string) $request->getQueryParam('state');
        $code = $request->getQueryParam('code');
        $error = $request->getQueryParam('error');

        if ($state !== '') {
            $this->service->handleCallback(
                $provider,
                $state,
                is_string($code) ? $code : null,
                is_string($error) ? $error : null
            );
        }

        $safeState = json_encode($state);
        $html = <<<HTML
<!doctype html>
<html><head><meta charset="utf-8"><title>Connecting inbox&hellip;</title></head>
<body style="font-family:sans-serif;color:#2b403b;padding:40px;text-align:center;">
<p>Finishing up&hellip; you can close this window if it doesn't close automatically.</p>
<script>
(function () {
    var state = {$safeState};
    if (window.opener) {
        window.opener.postMessage({source: 'nexa-mail-oauth', state: state}, window.location.origin);
    }
    window.close();
})();
</script>
</body></html>
HTML;

        return ResponseComposer::empty()
            ->writeBody($html)
            ->setHeader('Content-Type', 'text/html; charset=UTF-8')
            ->setHeader('Cache-Control', 'no-store');
    }
}
