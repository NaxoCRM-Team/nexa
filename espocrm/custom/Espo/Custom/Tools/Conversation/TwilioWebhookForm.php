<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Preserve signed form names and values without PHP's parse_str normalization. */
final class TwilioWebhookForm
{
    public static function parse(string $body, string $contentType): array
    {
        if (!preg_match('/^application\/x-www-form-urlencoded(?:\s*;\s*charset\s*=\s*"?utf-8"?)?\s*$/iD', trim($contentType))) {
            throw new BadRequest('Unsupported webhook content type.');
        }
        if ($body === '' || strlen($body) > 196608) {
            throw new BadRequest('Invalid webhook form size.');
        }
        $result = [];
        $bytes = 0;
        foreach (explode('&', $body) as $pair) {
            if ($pair === '' || count($result) >= 100 || !str_contains($pair, '=')) {
                throw new BadRequest('Invalid webhook form.');
            }
            [$key, $value] = explode('=', $pair, 2);
            if (preg_match('/%(?![0-9a-f]{2})/i', $key.$value)) {
                throw new BadRequest('Invalid webhook form encoding.');
            }
            $key = urldecode($key);
            $value = urldecode($value);
            $bytes += strlen($key) + strlen($value);
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D', $key) ||
                array_key_exists($key, $result) || $bytes > 65536 ||
                preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw new BadRequest('Invalid or ambiguous webhook field.');
            }
            $result[$key] = $value;
        }
        return $result;
    }
}
