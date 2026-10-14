<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/espocrm/bootstrap.php';
use Espo\Custom\Tools\Scoring\ScoringDefinition as Rules;
use Espo\Core\Exceptions\BadRequest;
$checks=0;
$assert=function(bool $value,string $message)use(&$checks){$checks++;if(!$value)throw new RuntimeException($message);};
$fields=['industry'=>['type'=>'enum','options'=>['Technology','Finance']],'size'=>['type'=>'decimal']];
$definition=['rules'=>[
    ['id'=>'fit','field'=>'industry','operator'=>'equals','value'=>'Technology','points'=>30],
    ['id'=>'scale','field'=>'size','operator'=>'greater_than','value'=>'9007199254740992','points'=>20],
    ['id'=>'penalty','field'=>'industry','operator'=>'equals','value'=>'Finance','points'=>-40],
]];
$result=Rules::evaluate($definition,$fields,['industry'=>'Technology','size'=>'9007199254740993']);
$assert($result['score']===50,'Exact decimal comparisons must not round large thresholds.');
$assert($result['contributions'][0]['points']===30 && $result['contributions'][1]['matched'],'Every contribution must explain its input and outcome.');
$assert(Rules::evaluate($definition,$fields,['size'=>'9007199254740993','industry'=>'Technology'])['inputHash']===$result['inputHash'],'Input identity is stable regardless of property order.');
$negative=Rules::evaluate($definition,$fields,['industry'=>'Finance']);
$assert($negative['score']===0 && $negative['rawScore']===-40,'Negative contributions are preserved even when the displayed score is bounded.');
$assert(Rules::evaluate($definition,$fields,[])['score']===0,'Missing data cannot silently match a comparison.');
foreach ([['rules'=>[]],['rules'=>array_fill(0,51,$definition['rules'][0])],['rules'=>[$definition['rules'][0],$definition['rules'][0]]],['rules'=>[['id'=>'x','field'=>'secret','operator'=>'equals','value'=>'x','points'=>1]]],['rules'=>[['id'=>'x','field'=>'industry','operator'=>'greater_than','value'=>'Technology','points'=>1]]],['rules'=>[['id'=>'x','field'=>'industry','operator'=>'equals','value'=>'Unconfigured','points'=>1]]],['rules'=>[['id'=>'x','field'=>'industry','operator'=>'has_value','points'=>10001]]]] as $invalid) {
    $blocked=false;try{Rules::validate($invalid,$fields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Invalid or unauthorized scoring rule must fail closed.');
}
echo "Scoring definition checks passed: $checks.\n";
foreach ([20=>'Cold',21=>'Engaged',50=>'Engaged',51=>'Marketing qualified',80=>'Marketing qualified',81=>'Sales ready'] as $points=>$label) {
    $banded=['rules'=>[['id'=>'band','field'=>'industry','operator'=>'has_value','points'=>$points]]];
    $assert(Rules::evaluate($banded,$fields,['industry'=>'Technology'])['band']===$label,'Default threshold boundaries must be exact.');
}
$bad=['rules'=>$definition['rules'],'thresholds'=>[['min'=>10,'label'=>'Missing baseline']]];$denied=false;try{Rules::validate($bad,$fields);}catch(BadRequest){$denied=true;}$assert($denied,'A threshold set without zero must fail validation.');
echo "Threshold checks included; total $checks.\n";
