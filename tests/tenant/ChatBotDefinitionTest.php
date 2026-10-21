<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/espocrm/bootstrap.php';

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Conversation\ChatBotDefinition;
use Espo\Custom\Tools\Conversation\ChatBotTransition;

$validator = new ChatBotDefinition();
$checks = 0;
$assert = function (bool $ok) use (&$checks): void {
    $checks++;
    if (!$ok) throw new RuntimeException('Chat bot definition assertion failed: '.$checks);
};
$reject = function (array $definition) use ($validator, $assert): void {
    try { $validator->validate($definition); } catch (BadRequest $error) { $assert(true); return; }
    $assert(false);
};
$flow = ['schemaVersion'=>1, 'entry'=>'welcome', 'nodes'=>[
    ['id'=>'welcome','type'=>'message','body'=>'Welcome','next'=>'question'],
    ['id'=>'question','type'=>'choice','body'=>'How can we help?','options'=>[
        ['label'=>'Speak to staff','next'=>'support'], ['label'=>'Nothing else','next'=>'done'],
    ]],
    ['id'=>'support','type'=>'handoff','body'=>'Our team will help you.'],
    ['id'=>'done','type'=>'end'],
]];
$result = $validator->validate($flow);
$assert(strlen($result['fingerprint']) === 64);
$reordered = $flow; $reordered['nodes'] = array_reverse($flow['nodes']);
$assert($validator->validate($reordered)['fingerprint'] === $result['fingerprint']);
$bad=$flow; $bad['nodes'][0]['next']='missing'; $reject($bad);
$bad=$flow; $bad['nodes'][0]['next']='welcome'; $reject($bad);
$bad=$flow; $bad['nodes'][]=['id'=>'unused','type'=>'end']; $reject($bad);
$bad=$flow; $bad['nodes'][]=$flow['nodes'][0]; $reject($bad);
$bad=$flow; $bad['nodes'][1]['options'][1]['label']='SPEAK TO STAFF'; $reject($bad);
$bad=$flow; unset($bad['nodes'][1]['options'][0]['next']); $reject($bad);
$bad=$flow; $bad['nodes'][2]['ownerId']='foreign-user'; $reject($bad);
$bad=$flow; $bad['nodes'][0]['type']='webhook.send'; $reject($bad);
$bad=$flow; $bad['tenantId']='foreign-tenant'; $reject($bad);
$bad=$flow; $bad['nodes'][0]['body']=str_repeat('a',4001); $reject($bad);
$bad=$flow; $bad['schemaVersion']=2; $reject($bad);
$bad=$flow; $bad['nodes'][1]['options']=[]; $reject($bad);
$bad=$flow; $bad['nodes'][0]['body']="bad\x00text"; $reject($bad);
$bad=$flow; $bad['nodes'][0]['body']="\xff"; $reject($bad);
$engine = new ChatBotTransition();
$start = $engine->advance($flow);
$assert($start['state'] === 'waiting' && count($start['messages']) === 2);
$assert($start['choices'] === ['Speak to staff', 'Nothing else']);
$handoff = $engine->advance($flow, 'question', 0);
$assert($handoff['state'] === 'handoff' && count($handoff['messages']) === 1);
$assert($engine->advance($flow, 'question', 1)['state'] === 'completed');
foreach ([['question', -1], ['question', 8], ['support', 0], ['missing', 0], [null, 0], ['question', null]] as [$step, $choice]) {
    try { $engine->advance($flow, $step, $choice); $assert(false); }
    catch (BadRequest $error) { $assert(true); }
}
$assert($engine->advance($flow) === $start);
echo "Chat bot definition and transitions: $checks checks passed.\n";
