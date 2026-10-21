<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/espocrm/bootstrap.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Conversation\TwilioWebhookForm;

$checks = 0;
$assert = function (bool $ok) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('Webhook form assertion failed.');
};
$parse = fn(string $body, string $type = 'application/x-www-form-urlencoded') => TwilioWebhookForm::parse($body, $type);
$deny = function (string $body, string $type = 'application/x-www-form-urlencoded') use ($parse, $assert): void {
    try { $parse($body, $type); } catch (BadRequest $e) { $assert(true); return; }
    $assert(false);
};
try {
    $assert($parse('Body=+hello%2Bworld+&FutureField=a%26b%3Dc') === ['Body'=>' hello+world ', 'FutureField'=>'a&b=c']);
    $assert($parse('Body=', 'Application/X-WWW-Form-Urlencoded; charset=UTF-8') === ['Body'=>'']);
    $assert($parse('Body=%2526') === ['Body'=>'%26']);
    foreach (['Body=a&Body=b', 'Body=a&%42ody=b', 'Body[]=a', 'Body.x=a', 'Body+x=a', '1=x', '=x', 'Body=%zz', 'Body=%', 'Body=%FF', 'Body=%00', 'Body=x&', 'Body', ''] as $body) $deny($body);
    $deny('Body=x', 'application/json');
    $deny('Body=x', 'application/x-www-form-urlencoded; charset=latin1');
    $deny('Body='.str_repeat('x', 65536));
    $deny('Body='.str_repeat('x', 196609));
    $fields = [];
    for ($i = 0; $i < 101; $i++) $fields[] = 'Field'.$i.'=x';
    $deny(implode('&', $fields));
    fwrite(STDOUT, "Passed {$checks} strict webhook form checks.\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
