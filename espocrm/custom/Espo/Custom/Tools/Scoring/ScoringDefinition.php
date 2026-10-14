<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\MarketingEmail\TemplateHelpers\DecimalComparison;

final class ScoringDefinition
{
    public static function validate(array $definition,array $fields): array
    {
        if (array_diff(array_keys($definition),['rules','thresholds']) || !is_array($definition['rules'] ?? null) || !array_is_list($definition['rules']) || count($definition['rules'])<1 || count($definition['rules'])>50) throw new BadRequest('Add between 1 and 50 scoring rules.');
        if (isset($definition['thresholds'])) {
            if (!is_array($definition['thresholds']) || !array_is_list($definition['thresholds']) || count($definition['thresholds'])<1 || count($definition['thresholds'])>10) throw new BadRequest('Configure between 1 and 10 score bands.');
            $previous=-1;$labels=[];
            foreach ($definition['thresholds'] as $index=>$band) {
                if (!is_array($band) || array_diff(array_keys($band),['min','label']) || !is_int($band['min']??null) || $band['min']<0 || $band['min']>1000000 || $band['min']<=$previous || ($index===0 && $band['min']!==0) || !is_string($band['label']??null) || trim($band['label'])==='' || mb_strlen($band['label'])>60 || isset($labels[$band['label']])) throw new BadRequest('Score bands must start at zero and increase, with distinct labels.');
                $previous=$band['min'];$labels[$band['label']]=true;
            }
        }
        $ids=[];
        foreach ($definition['rules'] as $rule) {
            if (!is_array($rule) || array_diff(array_keys($rule),['id','field','operator','value','points']) || !is_string($rule['id']??null) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$rule['id']) || isset($ids[$rule['id']])) throw new BadRequest('Every scoring rule needs a distinct identifier.');
            $ids[$rule['id']]=true;
            if (!is_string($rule['field']??null) || !isset($fields[$rule['field']]) || !is_int($rule['points']??null) || abs($rule['points'])>10000 || !in_array($rule['operator']??null,['equals','not_equals','greater_than','less_than','has_value'],true)) throw new BadRequest('Choose an available property, operator and integer points from -10,000 to 10,000.');
            $numeric=in_array($fields[$rule['field']]['type'],['int','float','currency','decimal'],true);
            if (isset($fields[$rule['field']]['event']) && $rule['operator']==='has_value') throw new BadRequest('Use a numeric comparison for a behavior count.');
            if (!$numeric && in_array($rule['operator'],['greater_than','less_than'],true)) throw new BadRequest('Ordering comparisons require a numeric property.');
            if ($rule['operator']!=='has_value') {
                if (!is_string($rule['value']??null) || strlen($rule['value'])>255) throw new BadRequest('Enter a scoring comparison value.');
                if ($numeric) DecimalComparison::canonical($rule['value']);
                if ($fields[$rule['field']]['type']==='enum' && !in_array($rule['value'],$fields[$rule['field']]['options']??[],true)) throw new BadRequest('Choose a configured property option.');
            }
        }
        return $definition;
    }

    public static function evaluate(array $definition,array $fields,array $inputs): array
    {
        self::validate($definition,$fields);$total=0;$reasons=[];$used=[];
        foreach ($definition['rules'] as $rule) {
            $actual=$inputs[$rule['field']]??null;$used[$rule['field']]=$actual;
            $present=$actual!==null && $actual!=='';$matched=false;
            if ($rule['operator']==='has_value') $matched=$present;
            elseif ($present) {
                if (in_array($fields[$rule['field']]['type'],['int','float','currency','decimal'],true)) {
                    $a=DecimalComparison::canonical((string)$actual);$b=DecimalComparison::canonical($rule['value']);
                    $cmp=$a[0]!==$b[0]?($a[0]==='-'?-1:1):strcmp(substr($a,1),substr($b,1))*($a[0]==='-'?-1:1);
                } else $cmp=strcmp((string)$actual,$rule['value']);
                $matched=match($rule['operator']){'equals'=>$cmp===0,'not_equals'=>$cmp!==0,'greater_than'=>$cmp>0,'less_than'=>$cmp<0};
            }
            $points=$matched?$rule['points']:0;$total+=$points;
            $reasons[]=['id'=>$rule['id'],'field'=>$rule['field'],'operator'=>$rule['operator'],'expected'=>$rule['value']??null,'actual'=>$actual,'matched'=>$matched,'points'=>$points];
        }
        ksort($used);
        $score=max(0,min(1000000,$total));$band=null;
        $thresholds=$definition['thresholds']??[['min'=>0,'label'=>'Cold'],['min'=>21,'label'=>'Engaged'],['min'=>51,'label'=>'Marketing qualified'],['min'=>81,'label'=>'Sales ready']];
        foreach ($thresholds as $threshold) if ($score>=$threshold['min']) $band=$threshold['label'];
        return ['score'=>$score,'band'=>$band,'thresholds'=>$thresholds,'rawScore'=>$total,'contributions'=>$reasons,'inputs'=>$used,'inputHash'=>hash('sha256',json_encode($used,JSON_THROW_ON_ERROR))];
    }
}
