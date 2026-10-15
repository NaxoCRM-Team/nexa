<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Scoring;

use Espo\Core\Exceptions\BadRequest;

/** Qualification promotions only; commercial/customer stages require explicit CRM actions. */
final class LifecycleScorePolicy
{
    public const STAGES=['Subscriber','Lead','MarketingQualifiedLead','SalesQualifiedLead'];
    public static function validate(array $policy,array $options): void
    {
        if (array_diff(array_keys($policy),['thresholds']) || !is_array($policy['thresholds']??null) || !array_is_list($policy['thresholds']) || count($policy['thresholds'])<1 || count($policy['thresholds'])>4) throw new BadRequest('Configure one to four lifecycle promotion thresholds.');
        $lastMin=-1;$lastRank=-1;
        foreach ($policy['thresholds'] as $index=>$threshold) {
            if (!is_array($threshold)) throw new BadRequest('Enter a lifecycle threshold and stage.');
            $rank=is_string($threshold['stage']??null)?array_search($threshold['stage'],self::STAGES,true):false;
            if (!is_array($threshold) || array_diff(array_keys($threshold),['min','stage']) || !is_int($threshold['min']??null) || $threshold['min']<0 || $threshold['min']>1000000 || $threshold['min']<=$lastMin || ($index===0 && $threshold['min']!==0) || $rank===false || $rank<=$lastRank || !in_array($threshold['stage'],$options,true)) throw new BadRequest('Lifecycle thresholds must start at zero and increase through available qualification stages.');
            $lastMin=$threshold['min'];$lastRank=$rank;
        }
    }
    public static function decide(?array $policy,mixed $current,int $score): array
    {
        $stage=is_string($current)?$current:'';
        if (!$policy) return ['previous'=>$stage,'target'=>$stage,'eligible'=>false,'applied'=>false,'reason'=>'disabled'];
        $rank=$stage===''?-1:array_search($stage,self::STAGES,true);
        if ($rank===false) return ['previous'=>$stage,'target'=>$stage,'eligible'=>false,'applied'=>false,'reason'=>'protected_stage'];
        $target=$stage;
        foreach ($policy['thresholds'] as $threshold) if ($score>=$threshold['min']) $target=$threshold['stage'];
        $promote=array_search($target,self::STAGES,true)>$rank;
        return ['previous'=>$stage,'target'=>$promote?$target:$stage,'eligible'=>$promote,'applied'=>false,'reason'=>$promote?'promotion':'no_demotion'];
    }
}
