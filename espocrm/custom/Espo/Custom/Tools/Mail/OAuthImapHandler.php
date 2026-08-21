<?php

namespace Espo\Custom\Tools\Mail;

use RuntimeException;

/**
 * Duck-typed extension point Core/Mail/Account/PersonalAccount/StorageFactory
 * looks for via EmailAccount.imapHandler (a *different* shape than the SMTP
 * Handler interface OAuthSmtpHandler implements - confirmed by reading
 * StorageFactory::createWithParams(), which calls prepareProtocol($id, $raw)
 * if that method exists on the named handler class). Returning an
 * already-authenticated OAuthImapProtocol instance takes the
 * "$params instanceof Protocol\Imap" fast path in Laminas's Storage\Imap
 * constructor, skipping its own (password-only) login entirely.
 */
final class OAuthImapHandler
{
    public function __construct(private MailOAuthService $mailOAuthService) {}

    /** @param array<string,mixed> $rawParams */
    public function prepareProtocol(?string $id, array $rawParams): OAuthImapProtocol
    {
        if ($id === null) {
            throw new RuntimeException('OAuth mailbox connection has no EmailAccount id.');
        }

        $token = $this->mailOAuthService->getValidAccessToken($id);

        $protocol = new OAuthImapProtocol();
        $protocol->connect((string) $rawParams['host'], $rawParams['port'] ?? null, $rawParams['ssl'] ?? 'ssl');

        if (!$protocol->authenticateXoauth2($token['emailAddress'], $token['accessToken'])) {
            throw new RuntimeException('IMAP XOAUTH2 authentication failed for ' . $token['emailAddress']);
        }

        return $protocol;
    }
}
