<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

/** Shared form-webhook signature primitive; the secret is supplied by the caller. */
final class TwilioWebhookSignature
{
    public static function verify(string $url, array $params, string $signature, string $secret): bool
    {
        if ($secret === '' || !preg_match('/^[A-Za-z0-9+\/]{27}=$/D', $signature)) return false;
        ksort($params, SORT_STRING);
        $data = $url;
        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_string($value)) return false;
            $data .= $key.$value;
        }
        return hash_equals(base64_encode(hash_hmac('sha1', $data, $secret, true)), $signature);
    }
}
