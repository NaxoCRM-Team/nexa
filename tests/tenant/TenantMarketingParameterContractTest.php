<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\MarketingParameterContract;

$contract = new MarketingParameterContract();
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$blocked = static function (stdClass $schema, stdClass $input) use ($contract, $assert): void {
    $denied = false;
    try {$contract->validate($schema, $input);} catch (BadRequest) {$denied = true;}
    $assert($denied, 'Unsafe or mismatched parameter input must be rejected.');
};
$schema = (object) ['reference' => 40, 'message' => 255];
$first = $contract->validate($schema, (object) ['reference' => 'ORDER-42', 'message' => 'Your appointment is confirmed.']);
$reordered = $contract->validate((object) ['message' => 255, 'reference' => 40], (object) ['message' => 'Your appointment is confirmed.', 'reference' => 'ORDER-42']);
$assert($first === $reordered, 'Object key order must not change canonical request identity.');
$changed = $contract->validate($schema, (object) ['reference' => 'ORDER-43', 'message' => 'Your appointment is confirmed.']);
$assert($first['fingerprint'] !== $changed['fingerprint'], 'Changed values must change request identity.');
$differentLimit = $contract->validate((object) ['reference' => 41, 'message' => 255], (object) $first['values']);
$assert($first['fingerprint'] !== $differentLimit['fingerprint'], 'The approved contract must contribute to request identity.');
foreach (['', '  ', "line\nheader", "a\0b", '{{leadScore}}', '{Contact.emailAddress}', '<img src=x>', "\xC3\x28", "hidden\u{202E}text", str_repeat('a', 256), 123, true, null, [], (object) []] as $unsafe) {
    $blocked($schema, (object) ['reference' => 'ORDER-42', 'message' => $unsafe]);
}
$blocked($schema, (object) ['reference' => 'ORDER-42']);
$blocked($schema, (object) ['reference' => 'ORDER-42', 'message' => 'Hello', 'sender' => 'override']);
foreach ([(object) ['__context' => 10], (object) ['a.b' => 10], (object) ['a' => '10'], (object) ['a' => 0], (object) ['a' => 256]] as $invalid) {
    $blocked($invalid, (object) ['a' => 'Hello']);
}
$manySchema = $manyInput = [];
for ($i = 0; $i < 21; $i++) {$manySchema['p' . $i] = 255; $manyInput['p' . $i] = 'Hello';}
$blocked((object) $manySchema, (object) $manyInput);
unset($manySchema['p20'], $manyInput['p20']);
$manyInput = array_map(static fn () => str_repeat('x', 255), $manyInput);
$blocked((object) $manySchema, (object) $manyInput);
$unicode = $contract->validate((object) ['message' => 2], (object) ['message' => "\u{00E9}\u{00E9}"]);
$assert($unicode['values']['message'] === "\u{00E9}\u{00E9}", 'Limits count Unicode characters without silently changing supplied text.');
$empty = $contract->validate(new stdClass(), new stdClass());
$assert($empty['values'] === [] && strlen($empty['fingerprint']) === 64, 'An email without parameters has a deterministic empty contract.');
echo "Marketing parameter contract tests passed.\n";

$template = new \Espo\Custom\Tools\MarketingEmail\MarketingParameterTemplate($contract);
$resolved = $template->resolve('Reference [[nexa:reference:40]]', '<p>[[nexa:message:255]] {Contact.firstName}</p>', '[[nexa:reference:40]]', (object) ['message'=>'A & B "confirmed"','reference'=>'ORDER-42']);
$assert($resolved['subject'] === 'Reference ORDER-42' && $resolved['preheader'] === 'ORDER-42', 'Subject and preheader must use the same validated snapshot.');
$assert($resolved['bodyHtml'] === '<p>A &amp; B &quot;confirmed&quot; {Contact.firstName}</p>', 'Body parameters must be escaped while native Contact tokens remain intact.');
foreach (['<a href="[[nexa:reference:40]]">Link</a>', '<!-- [[nexa:reference:40]] -->', '<style>[[nexa:reference:40]]</style>', '<script>[[nexa:reference:40]]</script>', '<[[nexa:reference:40]]>Hello</div>', '{{#ifEqual firstName "[[nexa:reference:40]]"}}Yes{{/ifEqual}}', '{Contact.[[nexa:reference:40]]}', '<p>[[nexa:reference:256]]</p>', '<p>[[nexa:reference:40]] [[nexa:reference:41]]</p>', '<p>[[nexa:reference:0]]</p>'] as $unsafeTemplate) {
    $denied = false;
    try {$template->schema('', $unsafeTemplate);} catch (BadRequest) {$denied = true;}
    $assert($denied, 'Unsafe template placement or declaration must be rejected: ' . $unsafeTemplate);
}
$denied = false;
try {$template->requireResolved('[[nexa:reference:40]]','<p>Hello</p>');} catch (BadRequest) {$denied = true;}
$assert($denied, 'Unresolved parameter emails cannot use ordinary delivery paths.');
$template->requireResolved('Hello','<p>{Contact.firstName}</p>');
echo "Marketing parameter template placement and snapshot tests passed.\n";
