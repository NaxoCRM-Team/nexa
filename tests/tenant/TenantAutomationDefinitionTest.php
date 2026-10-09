<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Automation\WorkflowDefinition;

$validator = new WorkflowDefinition();
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};
$base = ['schemaVersion' => 1, 'trigger' => ['event' => 'form.submitted', 'reenrollment' => 'never'], 'entry' => 'delay', 'nodes' => [
    ['id' => 'delay', 'type' => 'delay', 'seconds' => 60, 'next' => 'branch'],
    ['id' => 'branch', 'type' => 'branch', 'condition' => ['field' => 'leadScore', 'operator' => 'greater_than', 'value' => '50.00000001'], 'yes' => 'task', 'no' => 'end'],
    ['id' => 'task', 'type' => 'action', 'action' => 'task.create', 'settings' => ['name' => 'Follow up'], 'maxAttempts' => 3, 'retrySeconds' => 60, 'next' => 'end'],
    ['id' => 'end', 'type' => 'end'],
]];
$first = $validator->validate($base);
$assert(strlen($first['fingerprint']) === 64, 'A validated graph needs a stable SHA-256 identity.');
$reordered = array_reverse($base, true);
$reordered['nodes'] = array_reverse($base['nodes']);
foreach ($reordered['nodes'] as &$node) $node = array_reverse($node, true);
unset($node);
$assert($validator->validate($reordered) === $first, 'Canvas order and object key order must not change execution identity.');
$changed = $base;
$changed['nodes'][0]['seconds'] = 61;
$assert($validator->validate($changed)['fingerprint'] !== $first['fingerprint'], 'A changed wait must create a different immutable definition identity.');
$reject = static function (array $definition) use ($validator, $assert): void {
    $blocked = false;
    try {$validator->validate($definition);} catch (BadRequest) {$blocked = true;}
    $assert($blocked, 'Unsafe or incomplete workflow must be rejected.');
};
foreach ([0, '1', 2, null] as $version) {$bad = $base; $bad['schemaVersion'] = $version; $reject($bad);}
foreach (['unknown', null, 123] as $event) {$bad = $base; $bad['trigger']['event'] = $event; $reject($bad);}
$bad = $base; $bad['tenantId'] = 'other'; $reject($bad);
$bad = $base; $bad['trigger']['reenrollment'] = 'always'; $reject($bad);
$bad = $base; $bad['entry'] = 'missing'; $reject($bad);
$bad = $base; $bad['nodes'] = []; $reject($bad);
$bad = $base; $bad['nodes'][] = $base['nodes'][0]; $reject($bad);
$bad = $base; $bad['nodes'][] = ['id' => 'disconnected', 'type' => 'end']; $reject($bad);
$bad = $base; $bad['nodes'][0]['next'] = 'missing'; $reject($bad);
$bad = $base; $bad['nodes'][0]['next'] = 'delay'; $reject($bad);
$bad = $base; $bad['nodes'][2]['next'] = 'branch'; $reject($bad);
$bad = $base; unset($bad['nodes'][0]['next']); $reject($bad);
foreach ([0, -1, 31536001, '60', 1.5] as $seconds) {$bad = $base; $bad['nodes'][0]['seconds'] = $seconds; $reject($bad);}
foreach (['webhook.arbitrary', 'sql.execute', 'email.send_unchecked'] as $action) {$bad = $base; $bad['nodes'][2]['action'] = $action; $reject($bad);}
$bad = $base; $bad['nodes'][2]['settings']['tenantId'] = 'other'; $reject($bad);
foreach ([0, 6, '3'] as $attempts) {$bad = $base; $bad['nodes'][2]['maxAttempts'] = $attempts; $reject($bad);}
foreach (['owner.email', 'custom:Invalid', '__proto__'] as $field) {$bad = $base; $bad['nodes'][1]['condition']['field'] = $field; $reject($bad);}
foreach ([true, 1.2, '1e5', 'NaN', '0.123456789'] as $value) {$bad = $base; $bad['nodes'][1]['condition']['value'] = $value; $reject($bad);}
$bad = $base; $bad['nodes'][1]['condition'] = ['field' => 'emailAddress', 'operator' => 'has_value', 'value' => 'unexpected']; $reject($bad);
$empty = $base; $empty['nodes'][1]['condition'] = ['field' => 'custom:renewal_reference', 'operator' => 'has_value'];
$validator->validate($empty);
$email = $base;
$email['nodes'][2]['action'] = 'email.send';
$email['nodes'][2]['settings'] = ['sourceId' => 'approved1', 'version' => 1, 'parameters' => (object) ['reference' => 'ORDER-42']];
$validator->validate($email);
$bad = $email; $bad['nodes'][2]['settings']['parameters'] = []; $reject($bad);
$bad = $email; $bad['nodes'][2]['settings']['from'] = 'override@example.com'; $reject($bad);
$bad = $email; $bad['nodes'][2]['settings']['version'] = 0; $reject($bad);
$webhook = $base; $webhook['nodes'][2]['action'] = 'webhook.send'; $webhook['nodes'][2]['settings'] = ['webhookId' => 'registered1'];
$validator->validate($webhook);
$bad = $webhook; $bad['nodes'][2]['settings'] = ['url' => 'http://127.0.0.1/admin']; $reject($bad);
$hours = $base;
$hours['nodes'][0] = ['id' => 'delay', 'type' => 'business_hours', 'timezone' => 'Europe/London', 'weekdays' => [5, 1, 3], 'start' => '09:00', 'end' => '17:00', 'next' => 'branch'];
$sameHours = $hours; $sameHours['nodes'][0]['weekdays'] = [1, 3, 5];
$assert($validator->validate($hours) === $validator->validate($sameHours), 'Working-day order must not change graph identity.');
foreach (['Unknown/City', '+01:00', ''] as $zone) {$bad = $hours; $bad['nodes'][0]['timezone'] = $zone; $reject($bad);}
foreach ([[1, 1], [0], [8], ['1'], []] as $days) {$bad = $hours; $bad['nodes'][0]['weekdays'] = $days; $reject($bad);}
$bad = $hours; $bad['nodes'][0]['end'] = '08:00'; $reject($bad);
$bad = $hours; $bad['nodes'][0]['start'] = '24:00'; $reject($bad);
$wait = $base;
$wait['nodes'][1]['type'] = 'wait_condition';
$wait['nodes'][1]['timeoutSeconds'] = 3600;
$wait['nodes'][1]['pollSeconds'] = 60;
$validator->validate($wait);
$bad = $wait; $bad['nodes'][1]['pollSeconds'] = 3601; $reject($bad);
$bad = $wait; $bad['nodes'][1]['pollSeconds'] = 1; $reject($bad);
echo "Automation definition contract passed ($checks checks).\n";
