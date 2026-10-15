<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DecimalComparison;

final class ScoringDefinition
{
    public static function validate(array $definition,array $fields): array
    {
        if (array_diff(array_keys($definition),['rules','thresholds','lifecycle']) || !is_array($definition['rules'] ?? null) || !array_is_list($definition['rules']) || count($definition['rules'])<1 || count($definition['rules'])>50) throw new BadRequest('Add between 1 and 50 scoring rules.');
        if (array_key_exists('lifecycle',$definition)) {
            if (!is_array($definition['lifecycle']) || !isset($fields['lifecycleStage'])) throw new BadRequest('Lifecycle policies require an available native lifecycle property.');
            LifecycleScorePolicy::validate($definition['lifecycle'],$fields['lifecycleStage']['options']??[]);
        }
        if (isset($definition['thresholds'])) {
            if (!is_array($definition['thresholds']) || !array_is_list($definition['thresholds']) || count($definition['thresholds'])<1 || count($definition['thresholds'])>10) throw new BadRequest('Configure between 1 and 10 score bands.');
            $previous=-1;$labels=[];
            foreach ($definition['thresholds'] as $index=>$band) {
                if (!is_array($band) || array_diff(array_keys($band),['min','label']) || !is_int($band['min']??null) || $band['min']<0 || $band['min']>1000000 || $band['min']<=$previous || ($index===0 && $band['min']!==0) || !is_string($band['label']??null) || trim($band['label'])==='' || mb_strlen($band['label'])>60 || isset($labels[$band['label']])) throw new BadRequest('Score bands must start at zero and increase, with distinct labels.');
                $previous=$band['min'];$labels[$band['label']]=true;
            }
        }
        $ids=[];$conditionCount=0;
        foreach ($definition['rules'] as $rule) {
            if (is_array($rule) && array_key_exists('conditions',$rule)) {
                if (array_diff(array_keys($rule),['id','points','category','conditions']) || !is_string($rule['id']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$rule['id']) || isset($ids[$rule['id']]) || !is_int($rule['points']??null) || abs($rule['points'])>10000 || !in_array($rule['category']??null,['fit','engagement'],true) || !is_array($rule['conditions'])) throw new BadRequest('Grouped rules require a distinct identifier, category and valid integer points.');
                if (!isset($rule['conditions']['conditions'])) throw new BadRequest('A grouped rule requires an AND/OR condition group.');
                $ids[$rule['id']]=true;ScoringConditions::validate($rule['conditions'],$fields,0,$conditionCount);
                if ($conditionCount>100) throw new BadRequest('A scoring model supports at most 100 conditions.');
                continue;
            }
            if (++$conditionCount>100) throw new BadRequest('A scoring model supports at most 100 conditions.');
            if (!is_array($rule) || array_diff(array_keys($rule),['id','field','operator','value','points','propertyHash','decay','category','currency']) || !is_string($rule['id']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$rule['id']) || isset($ids[$rule['id']])) throw new BadRequest('Every scoring rule needs a distinct identifier.');
            if (array_key_exists('category',$rule) && !in_array($rule['category'],['fit','engagement'],true)) throw new BadRequest('Choose fit or engagement for each scoring rule.');
            $ids[$rule['id']]=true;
            if (!is_string($rule['field']??null) || !isset($fields[$rule['field']]) || !is_int($rule['points']??null) || abs($rule['points'])>10000 || !in_array($rule['operator']??null,['equals','not_equals','greater_than','less_than','has_value'],true)) throw new BadRequest('Choose an available property, operator and integer points from -10,000 to 10,000.');
            $propertyHash=$fields[$rule['field']]['propertyHash']??null;
            if (($propertyHash!==null && (!is_string($rule['propertyHash']??null) || !hash_equals($propertyHash,$rule['propertyHash']))) || ($propertyHash===null && isset($rule['propertyHash']))) throw new BadRequest('A custom scoring property changed. Save a new draft using its current definition.');
            $numeric=in_array($fields[$rule['field']]['type'],['int','float','currency','decimal'],true);
            if ($fields[$rule['field']]['type']==='currency') {
                if (!is_string($rule['currency']??null) || !in_array($rule['currency'],$fields[$rule['field']]['currencies']??[],true)) throw new BadRequest('Choose an enabled workspace currency for the monetary threshold.');
            } elseif (array_key_exists('currency',$rule)) throw new BadRequest('A comparison currency requires a monetary property.');
            if (isset($fields[$rule['field']]['event']) && $rule['operator']==='has_value') throw new BadRequest('Use a numeric comparison for a behavior count.');
            if (array_key_exists('decay',$rule)) {
                $decay=$rule['decay'];
                if (!isset($fields[$rule['field']]['event']) || $rule['points']<=0 || !is_array($decay) || array_diff(array_keys($decay),['afterDays','everyDays','points']) || !is_int($decay['afterDays']??null) || $decay['afterDays']<0 || $decay['afterDays']>3650 || !is_int($decay['everyDays']??null) || $decay['everyDays']<1 || $decay['everyDays']>365 || !is_int($decay['points']??null) || $decay['points']<1 || $decay['points']>10000) throw new BadRequest('Decay requires a positive behavior rule and valid inactivity intervals.');
            }
            if (!$numeric && in_array($rule['operator'],['greater_than','less_than'],true)) throw new BadRequest('Ordering comparisons require a numeric property.');
            if ($rule['operator']!=='has_value') {
                if (!is_string($rule['value']??null) || strlen($rule['value'])>255) throw new BadRequest('Enter a scoring comparison value.');
                if ($numeric) DecimalComparison::canonical($rule['value']);
                if ($fields[$rule['field']]['type']==='enum' && !in_array($rule['value'],$fields[$rule['field']]['options']??[],true)) throw new BadRequest('Choose a configured property option.');
            }
        }
        return $definition;
    }

    public static function evaluate(array $definition,array $fields,array $inputs,array $behaviorAges=[]): array
    {
        self::validate($definition,$fields);$total=0;$reasons=[];$used=[];$categories=['fit'=>0,'engagement'=>0];
        foreach ($definition['rules'] as $rule) {
            if (isset($rule['conditions'])) {
                $group=ScoringConditions::evaluate($rule['conditions'],$fields,$inputs);
                foreach (ScoringConditions::leaves($rule['conditions']) as $leaf) $used[$leaf['field']]=$inputs[$leaf['field']]??null;
                $points=$group['matched']?$rule['points']:0;$total+=$points;$categories[$rule['category']]+=$points;
                $reasons[]=['id'=>$rule['id'],'field'=>null,'actual'=>null,'expected'=>null,'operator'=>$group['operator'],'matched'=>$group['matched'],'points'=>$points,'basePoints'=>$points,'decayedPoints'=>0,'category'=>$rule['category'],'conditions'=>$group['conditions']];
                continue;
            }
            $actual=$inputs[$rule['field']]??null;$used[$rule['field']]=$actual;
            if ($fields[$rule['field']]['type']==='currency') $actual=is_array($actual)?($actual['normalized'][$rule['currency']]??null):null;
            $present=$actual!==null && $actual!=='';$matched=false;
            if ($rule['operator']==='has_value') $matched=$present;
            elseif ($present) {
                if (in_array($fields[$rule['field']]['type'],['int','float','currency','decimal'],true)) {
                    if ($fields[$rule['field']]['type']==='currency' && function_exists('bccomp')) {
                        if (!preg_match('/^-?\d{1,22}(?:\.\d{1,14})?$/D',(string)$actual)) throw new BadRequest('The converted monetary value exceeds the supported native precision.');
                        $cmp=bccomp((string)$actual,$rule['value'],14);
                    } else {
                        $a=DecimalComparison::canonical((string)$actual);$b=DecimalComparison::canonical($rule['value']);
                        $cmp=$a[0]!==$b[0]?($a[0]==='-'?-1:1):strcmp(substr($a,1),substr($b,1))*($a[0]==='-'?-1:1);
                    }
                } else $cmp=strcmp((string)$actual,$rule['value']);
                $matched=match($rule['operator']){'equals'=>$cmp===0,'not_equals'=>$cmp!==0,'greater_than'=>$cmp>0,'less_than'=>$cmp<0};
            }
            $points=$matched?$rule['points']:0;$basePoints=$points;$reduction=0;
            if ($matched && isset($rule['decay'])) {
                $age=$behaviorAges[$rule['field']]['days']??null;
                if (!is_int($age) || $age<0) $reduction=$points;
                else $reduction=min($points,intdiv(max(0,$age-$rule['decay']['afterDays']),$rule['decay']['everyDays'])*$rule['decay']['points']);
                $points-=$reduction;
            }
            $total+=$points;
            $category=$rule['category']??(isset($fields[$rule['field']]['event'])?'engagement':'fit');
            $categories[$category]+=$points;
            $reasons[]=['id'=>$rule['id'],'field'=>$rule['field'],'operator'=>$rule['operator'],'expected'=>$rule['value']??null,'actual'=>$actual,'matched'=>$matched,'points'=>$points,'basePoints'=>$basePoints,'decayedPoints'=>$reduction];
            if (isset($rule['currency'])) $reasons[array_key_last($reasons)]['currency']=$rule['currency'];
        }
        foreach ($reasons as $index=>&$reason) $reason['category']=$definition['rules'][$index]['category']??(isset($fields[$reason['field']??'']['event'])?'engagement':'fit');
        unset($reason);
        ksort($used);
        $score=max(0,min(1000000,$total));$band=null;
        $thresholds=$definition['thresholds']??[['min'=>0,'label'=>'Cold'],['min'=>21,'label'=>'Engaged'],['min'=>51,'label'=>'Marketing qualified'],['min'=>81,'label'=>'Sales ready']];
        foreach ($thresholds as $threshold) if ($score>=$threshold['min']) $band=$threshold['label'];
        $usedAges=[];
        foreach ($definition['rules'] as $rule) if (isset($rule['decay'])) $usedAges[$rule['field']]=$behaviorAges[$rule['field']]??null;
        ksort($usedAges);
        return ['score'=>$score,'band'=>$band,'thresholds'=>$thresholds,'rawScore'=>$total,'categoryScores'=>array_map(fn($value)=>max(0,min(1000000,$value)),$categories),'categoryRawScores'=>$categories,'contributions'=>$reasons,'inputs'=>$used,'behaviorAges'=>$usedAges,'inputHash'=>hash('sha256',json_encode($usedAges?['inputs'=>$used,'behaviorAges'=>$usedAges]:$used,JSON_THROW_ON_ERROR))];
    }

    public static function leaves(array $definition): array
    {
        $leaves=[];
        foreach ($definition['rules'] as $rule) $leaves=[...$leaves,...(isset($rule['conditions'])?ScoringConditions::leaves($rule['conditions']):[$rule])];
        return $leaves;
    }
}
