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
$filtered = $base; $filtered['trigger']['event'] = 'page.viewed'; $filtered['trigger']['pageUrl'] = 'https://example.test/Pricing?offer=1';
$assert($validator->validate($filtered)['definition']['trigger']['pageUrl'] === $filtered['trigger']['pageUrl'], 'Exact page URLs must retain path case and query identity.');
foreach (['', 'javascript:alert(1)', 'https://user:pass@example.test/', 'https://example.test/#section', 'https://example.test/has space', 123] as $url) {$bad = $filtered; $bad['trigger']['pageUrl'] = $url; $reject($bad);}
$bad = $filtered; $bad['trigger']['event'] = 'manual'; $reject($bad);
$bad = $base; $bad['entry'] = 'missing'; $reject($bad);
$counts = $base; $counts['nodes'][1]['condition'] = ['field'=>'behavior:page.viewed','operator'=>'count_at_least','value'=>'7','count'=>3];
$assert($validator->validate($counts)['definition']['nodes'] !== [], 'Bounded behavioral count conditions must validate.');
foreach ([-1,10001,1.5,'3',null] as $count) {$bad=$counts;$bad['nodes'][1]['condition']['count']=$count;$reject($bad);}
$bad=$counts;unset($bad['nodes'][1]['condition']['count']);$reject($bad);
$bad=$counts;$bad['nodes'][1]['condition']['operator']='within_days';$reject($bad);
$bad=$base;$bad['nodes'][1]['condition']['count']=3;$reject($bad);
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
$opportunity = $base;
$opportunity['nodes'][2]['action'] = 'opportunity.create';
$opportunity['nodes'][2]['settings'] = ['name' => 'Renewal', 'pipelineId' => 'pipeline-one', 'stage' => 'Prospecting', 'amount' => '1234.50', 'currency' => 'GBP', 'closeDate' => '2028-02-29'];
$assert($validator->validate($opportunity)['definition']['nodes'][3]['settings']['amount'] === '1234.50', 'Opportunity amount contracts must retain the submitted decimal string.');
foreach (['-1', '1e3', '1.001', '1000000000000', '', 12, 1.5] as $amount) {$bad = $opportunity; $bad['nodes'][2]['settings']['amount'] = $amount; $reject($bad);}
foreach (['2027-02-29', '2027-13-01', '01/01/2027'] as $date) {$bad = $opportunity; $bad['nodes'][2]['settings']['closeDate'] = $date; $reject($bad);}
$bad = $opportunity; $bad['nodes'][2]['settings']['currency'] = 'gbp'; $reject($bad);
$calendar = \Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DateComparison::class;
foreach (['equals'=>true,'not_equals'=>false,'before'=>false,'after'=>false,'on_or_before'=>true,'on_or_after'=>true,'has_value'=>true,'no_value'=>false] as $operator=>$expected) {
    $assert($calendar::matches('2028-02-29', $operator, '2028-02-29') === $expected, 'Date equality boundaries must be consistent for ' . $operator);
    $assert($calendar::matches(null, $operator, '2028-02-29') === ($operator === 'no_value'), 'Missing date semantics must be explicit for ' . $operator);
}
$timezone = date_default_timezone_get();
try {
    foreach (['Pacific/Kiritimati','America/Los_Angeles','UTC'] as $zone) {
        date_default_timezone_set($zone);
        $assert($calendar::matches('2028-02-29', 'before', '2028-03-01'), 'Calendar ordering must not change with worker timezone.');
    }
} finally {date_default_timezone_set($timezone);}
foreach (['2027-02-29','2028-03-01T00:00:00Z','2028-02-30',false] as $invalid) {
    $rejected = false;
    try {$calendar::canonical($invalid);} catch (BadRequest) {$rejected = true;}
    $assert($rejected, 'Invalid dates, timestamps and non-string values must be rejected.');
}
$timestampGraph=$base;
$timestampGraph['nodes'][1]['condition']=['field'=>'custom:appointment','operator'=>'on_or_after','value'=>'2028-02-29T10:00:00+01:00'];
$assert($validator->validate($timestampGraph)['definition']['nodes']!==[], 'Graphs must accept explicit-offset timestamp comparisons.');
foreach (['2028-02-29T10:00:00','2028-02-30T10:00:00Z','2028-02-29T10:00:00+15:00'] as $invalid) {
    $bad=$timestampGraph; $bad['nodes'][1]['condition']['value']=$invalid; $reject($bad);
}
$relativeGraph=$base;
$relativeGraph['nodes'][1]['condition']=['field'=>'custom:renewal','operator'=>'within_next_days','value'=>'30'];
$assert($validator->validate($relativeGraph)['definition']['nodes']!==[], 'Relative windows must be valid graph conditions.');
foreach (['-1','366','01','1.5',30,null] as $invalid) {$bad=$relativeGraph; $bad['nodes'][1]['condition']['value']=$invalid; $reject($bad);}
$pageGraph=$base; $pageGraph['nodes'][1]['condition']=['field'=>'behavior:page.viewed','operator'=>'count_equals','value'=>'30','count'=>2,'pageUrl'=>'https://example.test/Pricing?offer=1'];
$assert($validator->validate($pageGraph)['definition']['nodes']!==[],'Branch page filters must retain exact URL identity.');
foreach (['','https://user:secret@example.test/','https://example.test/#fragment','ftp://example.test/'] as $invalid) {$bad=$pageGraph; $bad['nodes'][1]['condition']['pageUrl']=$invalid; $reject($bad);}
foreach (['behavior:email.opened','firstName'] as $field) {$bad=$pageGraph; $bad['nodes'][1]['condition']['field']=$field; $reject($bad);}
$layout=$base;$layout['layout']=['delay'=>['x'=>10,'y'=>20],'end'=>['x'=>500,'y'=>20]];
$assert($validator->validate($layout)['fingerprint']===$first['fingerprint'],'Shared canvas positions must not change execution identity.');
$assert($validator->validate($layout)['definition']['layout']['delay']['x']===10,'Shared positions must remain in the persisted draft.');
foreach ([['missing'=>['x'=>1,'y'=>2]],['delay'=>['x'=>1.5,'y'=>2]],['delay'=>['x'=>-1,'y'=>2]],['delay'=>['x'=>1,'y'=>25001]],['delay'=>['x'=>1]]] as $positions) {$bad=$base;$bad['layout']=$positions;$reject($bad);}
$bad=$base;$bad['layout']=null;$reject($bad);
$absolute=$base;$absolute['nodes'][0]=['id'=>'delay','type'=>'wait_until','at'=>'2028-02-29T10:00:00+01:00','next'=>'branch'];
$assert($validator->validate($absolute)['definition']['nodes']!==[],'Explicit-offset absolute waits must validate.');
foreach (['2028-02-29T10:00:00','2027-02-29T10:00:00Z',null] as $at) {$bad=$absolute;$bad['nodes'][0]['at']=$at;$reject($bad);}
foreach ([['event'=>'contact.field_changed','field'=>'leadStatus'],['event'=>'score.reached','threshold'=>50],['event'=>'segment.entered','segmentId'=>'list-one']] as $trigger) {$valid=$base;$valid['trigger']=$trigger+['reenrollment'=>'never'];$assert($validator->validate($valid)['definition']!==[],'Configured native enrollment must validate.');}
foreach ([['event'=>'contact.field_changed'],['event'=>'contact.field_changed','field'=>null],['event'=>'score.reached','threshold'=>'50'],['event'=>'score.reached','threshold'=>-1],['event'=>'segment.exited'],['event'=>'manual','field'=>'firstName']] as $trigger) {$bad=$base;$bad['trigger']=$trigger+['reenrollment'=>'never'];$reject($bad);}
$sequence=$base;$sequence['nodes'][1]['condition']=['matchMode'=>'sequence','conditions'=>[['field'=>'behavior:email.opened','operator'=>'within_days','value'=>'30'],['field'=>'behavior:email.clicked','operator'=>'within_days','value'=>'30']]];
$assert($validator->validate($sequence)['definition']!==[],'Ordered canonical events must validate.');
$bad=$sequence;$bad['nodes'][1]['condition']['conditions'][1]['value']='7';$reject($bad);
$bad=$sequence;$bad['nodes'][1]['condition']['conditions'][1]['field']='firstName';$reject($bad);
$bad=$sequence;$bad['nodes'][1]['condition']['conditions'][1]['operator']='not_within_days';$reject($bad);
$campaignRule=$base;$campaignRule['nodes'][1]['condition']=['field'=>'behavior:email.clicked','operator'=>'within_days','value'=>'30','campaignId'=>'campaign-one'];
$assert($validator->validate($campaignRule)['definition']!==[],'Native campaign behavior filters must validate.');
$bad=$campaignRule;$bad['nodes'][1]['condition']['field']='behavior:page.viewed';$reject($bad);
$bad=$campaignRule;$bad['nodes'][1]['condition']['campaignId']='foreign/invalid';$reject($bad);
echo "Automation definition contract passed ($checks checks).\n";
