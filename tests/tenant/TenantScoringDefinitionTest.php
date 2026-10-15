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
\Espo\Custom\Tools\Scoring\LifecycleScorePolicy::validate(['thresholds'=>[['min'=>0,'stage'=>'Lead'],['min'=>80,'stage'=>'SalesQualifiedLead']]],['Lead','SalesQualifiedLead']);
$policy=['thresholds'=>[['min'=>0,'stage'=>'Lead'],['min'=>80,'stage'=>'SalesQualifiedLead']]];
$assert(\Espo\Custom\Tools\Scoring\LifecycleScorePolicy::decide($policy,'Lead',80)['eligible'],'Qualification promotion uses inclusive thresholds.');
$assert(!\Espo\Custom\Tools\Scoring\LifecycleScorePolicy::decide($policy,'SalesQualifiedLead',0)['eligible'],'Falling scores never demote qualified records.');
foreach (['Customer','Opportunity','Evangelist','Other','Unknown'] as $stage) $assert(\Espo\Custom\Tools\Scoring\LifecycleScorePolicy::decide($policy,$stage,100)['reason']==='protected_stage','Commercial and unknown stages are preserved.');
foreach ([['thresholds'=>[['min'=>1,'stage'=>'Lead']]],['thresholds'=>[['min'=>0,'stage'=>'Customer']]],['thresholds'=>[['min'=>0,'stage'=>'SalesQualifiedLead'],['min'=>80,'stage'=>'Lead']]],['thresholds'=>['invalid']]] as $invalid) {
    $blocked=false;try{\Espo\Custom\Tools\Scoring\LifecycleScorePolicy::validate($invalid,['Lead','SalesQualifiedLead']);}catch(BadRequest){$blocked=true;}$assert($blocked,'Malformed or backwards lifecycle policies fail closed.');
}
echo "Scoring definition checks passed: $checks.\n";
foreach ([20=>'Cold',21=>'Engaged',50=>'Engaged',51=>'Marketing qualified',80=>'Marketing qualified',81=>'Sales ready'] as $points=>$label) {
    $banded=['rules'=>[['id'=>'band','field'=>'industry','operator'=>'has_value','points'=>$points]]];
    $assert(Rules::evaluate($banded,$fields,['industry'=>'Technology'])['band']===$label,'Default threshold boundaries must be exact.');
}
$bad=['rules'=>$definition['rules'],'thresholds'=>[['min'=>10,'label'=>'Missing baseline']]];$denied=false;try{Rules::validate($bad,$fields);}catch(BadRequest){$denied=true;}$assert($denied,'A threshold set without zero must fail validation.');
echo "Threshold checks included; total $checks.\n";
$hash=hash('sha256','custom-property-definition');
$customFields=['custom:grade'=>['type'=>'enum','options'=>['Gold','Silver'],'propertyHash'=>$hash]];
$customRule=['id'=>'grade','field'=>'custom:grade','operator'=>'equals','value'=>'Gold','points'=>10,'propertyHash'=>$hash];
$assert(Rules::evaluate(['rules'=>[$customRule]],$customFields,['custom:grade'=>'Gold'])['score']===10,'Pinned custom properties use the existing rule engine.');
foreach ([array_replace($customRule,['propertyHash'=>str_repeat('0',64)]),array_diff_key($customRule,['propertyHash'=>true])] as $invalid) {
    $blocked=false;try{Rules::validate(['rules'=>[$invalid]],$customFields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Changed or missing custom schema fingerprints must fail closed.');
}
echo "Custom property checks included; total $checks.\n";
$behaviorFields=['clicks'=>['type'=>'int','event'=>'email.clicked']];
$decayRule=['id'=>'engaged','field'=>'clicks','operator'=>'greater_than','value'=>'0','points'=>30,'decay'=>['afterDays'=>7,'everyDays'=>3,'points'=>5]];
foreach ([7=>30,9=>30,10=>25,13=>20,100=>0] as $days=>$expected) {
    $result=Rules::evaluate(['rules'=>[$decayRule]],$behaviorFields,['clicks'=>2],['clicks'=>['days'=>$days,'lastEventAt'=>'2026-10-01 00:00:00']]);
    $assert($result['score']===$expected && $result['contributions'][0]['decayedPoints']===30-$expected,'Inactivity decay has exact boundaries and never reverses the contribution.');
}
$assert(Rules::evaluate(['rules'=>[$decayRule]],$behaviorFields,['clicks'=>2])['score']===0,'Missing authoritative activity age cannot bypass configured decay.');
foreach ([array_replace($decayRule,['points'=>-10]),array_replace($decayRule,['decay'=>['afterDays'=>0,'everyDays'=>0,'points'=>5]]),array_replace($decayRule,['field'=>'size'])] as $invalid) {
    $blocked=false;try{Rules::validate(['rules'=>[$invalid]],$behaviorFields+$fields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Decay is limited to valid positive behavioral rules.');
}
echo "Decay checks included; total $checks.\n";
$categorized=['rules'=>[['id'=>'fit','field'=>'industry','operator'=>'has_value','points'=>30,'category'=>'fit'],['id'=>'engagement','field'=>'clicks','operator'=>'greater_than','value'=>'0','points'=>20,'category'=>'engagement']]];
$result=Rules::evaluate($categorized,$fields+$behaviorFields,['industry'=>'Technology','clicks'=>2]);
$assert($result['score']===50 && $result['categoryScores']===['fit'=>30,'engagement'=>20],'Fit and engagement break down the original combined score.');
$assert($result['contributions'][1]['category']==='engagement','Contribution explanations retain category identity.');
$result=Rules::evaluate(['rules'=>[$decayRule]],$behaviorFields,['clicks'=>2],['clicks'=>['days'=>10]]);
$assert($result['categoryScores']['engagement']===25 && $result['categoryScores']['fit']===0,'Legacy behavior rules infer engagement after decay without rewriting definitions.');
$assert(Rules::evaluate($definition,$fields,['industry'=>'Finance'])['categoryRawScores']['fit']===-40,'Negative category evidence is retained separately from bounded display scores.');
$invalid=$categorized;$invalid['rules'][0]['category']='unknown';$blocked=false;try{Rules::validate($invalid,$fields+$behaviorFields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Unknown score categories fail closed.');
echo "Category checks included; total $checks.\n";
$condition=['operator'=>'and','conditions'=>[['field'=>'industry','operator'=>'equals','value'=>'Technology'],['operator'=>'or','conditions'=>[['field'=>'size','operator'=>'greater_than','value'=>'100'],['field'=>'industry','operator'=>'equals','value'=>'Finance']]]]];
$group=['id'=>'qualified','points'=>40,'category'=>'fit','conditions'=>$condition];
$result=Rules::evaluate(['rules'=>[$group]],$fields,['industry'=>'Technology','size'=>'101']);
$assert($result['score']===40 && count($result['contributions'][0]['conditions'])===2,'Nested AND/OR groups award points once and retain leaf evidence.');
$assert(Rules::evaluate(['rules'=>[$group]],$fields,['industry'=>'Technology','size'=>'99'])['score']===0,'A failed AND branch prevents the group award.');
$assert(Rules::evaluate(['rules'=>[$group]],$fields,['industry'=>'Finance','size'=>'101'])['score']===0,'OR matches cannot override a failed parent AND.');
$group['conditions']['operator']='or';
$assert(Rules::evaluate(['rules'=>[$group]],$fields,['industry'=>'Technology'])['score']===40,'A parent OR can match with missing data in another branch.');
$group['points']=-20;$assert(Rules::evaluate(['rules'=>[$group]],$fields,['industry'=>'Technology'])['categoryRawScores']['fit']===-20,'Grouped penalties retain their raw evidence.');
$deep=['field'=>'industry','operator'=>'has_value'];for($i=0;$i<4;$i++)$deep=['operator'=>'and','conditions'=>[$deep]];
$validGroup=['id'=>'depth','points'=>1,'category'=>'fit','conditions'=>$deep];$assert(Rules::evaluate(['rules'=>[$validGroup]],$fields,['industry'=>'Technology'])['score']===1,'Four group nesting levels are supported.');
$invalidGroups=[['id'=>'empty','points'=>1,'category'=>'fit','conditions'=>['operator'=>'and','conditions'=>[]]],['id'=>'deep','points'=>1,'category'=>'fit','conditions'=>['operator'=>'and','conditions'=>[$deep]]],['id'=>'leaf','points'=>1,'category'=>'fit','conditions'=>['field'=>'industry','operator'=>'has_value']],['id'=>'bad','points'=>1,'category'=>'fit','conditions'=>['operator'=>'or','conditions'=>[['field'=>'secret','operator'=>'has_value']]]]];
foreach($invalidGroups as $bad){$blocked=false;try{Rules::validate(['rules'=>[$bad]],$fields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Invalid, inaccessible and excessively deep groups fail closed.');}
$moneyFields=['amount'=>['type'=>'currency','currencies'=>['USD','GBP']]];
$money=['id'=>'value','field'=>'amount','operator'=>'greater_than','value'=>'99.99','currency'=>'GBP','points'=>10];
$moneyInput=['amount'=>['amount'=>'125','code'=>'USD','normalized'=>['GBP'=>'100.00'],'rates'=>['USD'=>1,'GBP'=>1.25]]];
$result=Rules::evaluate(['rules'=>[$money]],$moneyFields,$moneyInput);
$assert($result['score']===10 && $result['contributions'][0]['currency']==='GBP' && $result['contributions'][0]['actual']==='100.00','Currency thresholds compare the explicit normalized currency and retain evidence.');
$assert(Rules::evaluate(['rules'=>[$money]],$moneyFields,['amount'=>null])['score']===0,'Missing money values do not match.');
$changed=$moneyInput;$changed['amount']['rates']['GBP']=1.3;$assert(Rules::evaluate(['rules'=>[$money]],$moneyFields,$changed)['inputHash']!==$result['inputHash'],'Rate evidence participates in evaluation identity.');
foreach([array_diff_key($money,['currency'=>true]),array_replace($money,['currency'=>'EUR'])] as $bad){$blocked=false;try{Rules::validate(['rules'=>[$bad]],$moneyFields);}catch(BadRequest){$blocked=true;}$assert($blocked,'Missing and disabled threshold currencies fail closed.');}
echo "Advanced scoring checks included; total $checks.\n";
$hundred=['operator'=>'or','conditions'=>array_fill(0,10,['operator'=>'and','conditions'=>array_fill(0,10,['field'=>'industry','operator'=>'has_value'])])];
$atLimit=['id'=>'limit','points'=>1,'category'=>'fit','conditions'=>$hundred];$assert(Rules::evaluate(['rules'=>[$atLimit]],$fields,['industry'=>'Technology'])['score']===1,'A 100-condition model awards one group result.');
$overLimit=['rules'=>[$atLimit,['id'=>'extra','field'=>'industry','operator'=>'has_value','points'=>1]]];$blocked=false;try{Rules::validate($overLimit,$fields);}catch(BadRequest){$blocked=true;}$assert($blocked,'The total condition cap includes grouped and standalone rules.');
$money['value']='33.33333333';$money['operator']='greater_than';$assert(Rules::evaluate(['rules'=>[$money]],$moneyFields,['amount'=>['normalized'=>['GBP'=>'33.33333333333333']]])['score']===10,'Native 14-place converted decimals remain exact at threshold boundaries.');
echo "Advanced bounds and precision included; total $checks.\n";
