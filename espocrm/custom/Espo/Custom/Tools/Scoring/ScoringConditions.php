<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Exceptions\BadRequest;

/** Bounded condition trees share the existing scalar scoring comparisons. */
final class ScoringConditions
{
    public static function validate(array $node,array $fields,int $depth=0,?int &$count=null): void
    {
        $count??=0;
        if ($depth>4 || ($depth>3 && isset($node['conditions']))) throw new BadRequest('Scoring groups support at most four nesting levels.');
        if (array_key_exists('conditions',$node)) {
            if (array_diff(array_keys($node),['operator','conditions']) || !in_array($node['operator']??null,['and','or'],true) || !is_array($node['conditions']) || !array_is_list($node['conditions']) || count($node['conditions'])<1 || count($node['conditions'])>10) throw new BadRequest('Each group needs an AND/OR operator and one to ten conditions.');
            foreach ($node['conditions'] as $child) {
                if (!is_array($child)) throw new BadRequest('Enter a valid scoring condition.');
                self::validate($child,$fields,$depth+1,$count);
            }
            return;
        }
        if (++$count>100) throw new BadRequest('A scoring model supports at most 100 conditions.');
        if (array_diff(array_keys($node),['field','operator','value','propertyHash','currency'])) throw new BadRequest('Unsupported scoring condition settings.');
        ScoringDefinition::validate(['rules'=>[['id'=>'condition','points'=>0]+$node]],$fields);
    }

    public static function leaves(array $node): array
    {
        if (!isset($node['conditions'])) return [$node];
        $leaves=[];
        foreach ($node['conditions'] as $child) $leaves=[...$leaves,...self::leaves($child)];
        return $leaves;
    }

    public static function bind(array &$node,array $fields,int $depth=0,?int &$count=null): void
    {
        $count??=0;
        if ($depth>4 || ($depth>3 && isset($node['conditions']))) throw new BadRequest('Scoring groups support at most four nesting levels.');
        if (isset($node['conditions']) && is_array($node['conditions'])) {
            if (count($node['conditions'])>10) throw new BadRequest('A condition group supports at most ten children.');
            foreach ($node['conditions'] as &$child) if (is_array($child)) self::bind($child,$fields,$depth+1,$count);
            unset($child);
            return;
        }
        if (++$count>100) throw new BadRequest('A scoring group supports at most 100 conditions.');
        $field=is_string($node['field']??null)?($fields[$node['field']]??[]):[];
        if (isset($field['propertyHash'])) $node['propertyHash']=$field['propertyHash'];
    }

    public static function evaluate(array $node,array $fields,array $inputs): array
    {
        if (!isset($node['conditions'])) {
            $result=ScoringDefinition::evaluate(['rules'=>[['id'=>'condition','points'=>0]+$node]],$fields,$inputs);
            return $result['contributions'][0];
        }
        $children=array_map(fn($child)=>self::evaluate($child,$fields,$inputs),$node['conditions']);
        $matches=array_column($children,'matched');
        return ['operator'=>$node['operator'],'conditions'=>$children,'matched'=>$node['operator']==='and'?!in_array(false,$matches,true):in_array(true,$matches,true)];
    }
}
