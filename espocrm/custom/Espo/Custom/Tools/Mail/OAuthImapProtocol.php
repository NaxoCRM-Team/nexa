<?php

namespace Espo\Custom\Tools\Mail;

use Laminas\Mail\Protocol\Imap;

/**
 * Laminas's IMAP client has no OAuth support at all (unlike Symfony Mailer's
 * SMTP transport, which ships a ready-made XOAUTH2 authenticator) - this adds
 * the one thing it's missing, using the same protected primitives its own
 * login() uses internally.
 */
final class OAuthImapProtocol extends Imap
{
    /**
     * Sends the standard SASL XOAUTH2 initial-response line directly (no
     * quoting/escaping - it's a raw base64 token, not an IMAP string
     * literal). A server that rejects the token responds with a '+'
     * continuation carrying a base64 error payload and then WAITS for an
     * empty line before it will send the final tagged failure - without
     * handling that explicitly, waiting for the tagged response would block
     * indefinitely, since neither side would ever send it.
     */
    public function authenticateXoauth2(string $emailAddress, string $accessToken): bool
    {
        $authString = base64_encode("user={$emailAddress}\x01auth=Bearer {$accessToken}\x01\x01");

        $tag = null;
        $this->sendRequest('AUTHENTICATE XOAUTH2', [$authString], $tag);

        // Found the hard way against real Gmail: a server can send unrelated
        // untagged ('*') lines - e.g. an unsolicited CAPABILITY announcement
        // - between our command and its actual response. A single blind
        // nextLine() read misreads that noise as the answer and reports
        // failure even when the token is genuinely valid. Skip anything
        // that isn't either the '+' failure-challenge or our own tagged
        // completion line. Bounded by the socket's own read timeout inside
        // nextLine(), same as every other exchange in this class - never a
        // true infinite loop.
        while (true) {
            $line = $this->nextLine();

            if (str_starts_with($line, '+')) {
                fwrite($this->socket, "\r\n");
                continue;
            }

            if (str_starts_with($line, $tag . ' ')) {
                return str_starts_with($line, $tag . ' OK');
            }
        }
    }
}
