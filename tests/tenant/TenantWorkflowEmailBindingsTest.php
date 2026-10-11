<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/espocrm/bootstrap.php';

use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Custom\Tools\Automation\{WorkflowEmailBindings, WorkflowDefinition};
use Espo\Custom\Tools\MarketingEmail\{MarketingParameterTemplate, MarketingParameterContract};

$checks = 0;
$assert = static function (bool $value, string $message) use (&$checks): void {
    $checks++;
    if (!$value) throw new RuntimeException($message);
};
$resolver = new WorkflowEmailBindings();
$fields = [['name'=>'firstName','type'=>'varchar'],['name'=>'leadScore','type'=>'int'],['name'=>'custom:budget','type'=>'decimal']];
$settings = ['sourceId'=>'approved-source','version'=>1,'parameters'=>(object) ['offer'=>'Renewal'], 'parameterBindings'=>(object) ['recipient'=>(object) ['field'=>'firstName','fallback'=>'Customer']]];
$assert($resolver->resolve($settings,$fields)->recipient === 'Customer', 'Publication must validate configured fallback text.');
$assert($resolver->resolve($settings,$fields,fn ($field)=>'Ada')->recipient === 'Ada', 'Execution must reread the current Contact property.');
$assert($resolver->resolve($settings,$fields,fn ($field)=>null)->recipient === 'Customer', 'Missing values may use an explicit fallback.');
$assert($resolver->resolve($settings,$fields,fn ($field)=>'New name')->recipient === 'New name', 'Bindings must not freeze publication-time data.');
$denied = static function (callable $call) use ($assert): void {
    $rejected=false;
    try {$call();} catch (BadRequest | Forbidden $error) {$rejected=true;}
    $assert($rejected,'Invalid or inaccessible bindings must fail closed.');
};
$denied(fn ()=>$resolver->resolve($settings,[]));
$denied(fn ()=>$resolver->resolve($settings,[],fn ($field)=>'secret'));
foreach (['behavior:page.viewed','custom:Invalid','__proto__','account.name'] as $field) {
    $bad=$settings;$bad['parameterBindings']=(object) ['recipient'=>(object) ['field'=>$field]];
    $denied(fn ()=>$resolver->resolve($bad,$fields));
}
$bad=$settings;$bad['parameterBindings']=(object) ['offer'=>(object) ['field'=>'firstName']];
$denied(fn ()=>$resolver->resolve($bad,$fields));
$bad=$settings;$bad['parameterBindings']=(object) ['recipient'=>(object) ['field'=>'firstName','tenantId'=>'foreign']];
$denied(fn ()=>$resolver->resolve($bad,$fields));
$noFallback=$settings;$noFallback['parameterBindings']=(object) ['recipient'=>(object) ['field'=>'firstName']];
$denied(fn ()=>$resolver->resolve($noFallback,$fields,fn ($field)=>null));
foreach (['',"line\nbreak",str_repeat('x',256),false,['secret'],1.5,'<b>Ada</b>','{secret}'] as $value) {
    $denied(fn ()=>$resolver->resolve($noFallback,$fields,fn ($field)=>$value));
}
$numeric=$settings;$numeric['parameterBindings']=(object) ['recipient'=>(object) ['field'=>'leadScore']];
$assert($resolver->resolve($numeric,$fields,fn ($field)=>0)->recipient === '0','Zero must not be mistaken for a missing value.');
$numeric['parameterBindings']=(object) ['recipient'=>(object) ['field'=>'custom:budget']];
$assert($resolver->resolve($numeric,$fields,fn ($field)=>'9007199254740993.12345677')->recipient === '9007199254740993.12345677','Custom decimals must preserve exact stored text.');
$graph=['schemaVersion'=>1,'trigger'=>['event'=>'manual','reenrollment'=>'never'],'entry'=>'send','nodes'=>[
    ['id'=>'send','type'=>'action','action'=>'email.send','settings'=>$settings,'maxAttempts'=>1,'retrySeconds'=>60,'next'=>'end'],['id'=>'end','type'=>'end'],
]];
$definition=(new WorkflowDefinition())->validate($graph);
$assert(strlen($definition['fingerprint'])===64,'Bindings must participate in immutable graph identity.');
$changed=$graph;$changed['nodes'][0]['settings']['parameterBindings']=(object) ['recipient'=>(object) ['field'=>'lastName']];
$assert((new WorkflowDefinition())->validate($changed)['fingerprint']!==$definition['fingerprint'],'Changing a value source must change the workflow fingerprint.');
$template=new MarketingParameterTemplate(new MarketingParameterContract());
$values=$resolver->resolve($settings,$fields,fn ($field)=>'Ada & Company');
$rendered=$template->resolve('Hello [[nexa:recipient:40]]','<p>[[nexa:recipient:40]] - [[nexa:offer:20]]</p>','',$values);
$assert(str_contains($rendered['bodyHtml'],'Ada &amp; Company'),'Bound values must be escaped by the existing renderer, never interpreted as HTML.');
$denied(fn ()=>$template->resolve('[[nexa:recipient:1]]','[[nexa:offer:20]]','',$values));
echo "Workflow email bindings passed ($checks checks).\n";
