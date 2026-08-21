<?php

namespace Espo\Custom\Tools\Mail;

use Espo\Core\Mail\Smtp\Handler;
use Espo\Core\Mail\SmtpParams;
use RuntimeException;

/**
 * The sanctioned-but-previously-unused extension point (see
 * Core/Mail/Smtp/HandlerProcessor - "@todo For xoauth, set authMechanism,
 * username, password in handlers" in DefaultTransportPreparator). Invoked
 * automatically by core whenever an EmailAccount has smtpHandler set to
 * this class name; $id is that EmailAccount's own id.
 */
final class OAuthSmtpHandler implements Handler
{
    public function __construct(private MailOAuthService $mailOAuthService) {}

    public function handle(SmtpParams $params, ?string $id): SmtpParams
    {
        if ($id === null) {
            throw new RuntimeException('OAuth mailbox connection has no EmailAccount id.');
        }

        $token = $this->mailOAuthService->getValidAccessToken($id);
        $authString = base64_encode("user={$token['emailAddress']}\x01auth=Bearer {$token['accessToken']}\x01\x01");

        return $params->withConnectionOptions(['authString' => $authString]);
    }
}
