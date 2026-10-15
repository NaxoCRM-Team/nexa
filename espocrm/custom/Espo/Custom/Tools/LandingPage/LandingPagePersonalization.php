<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\LandingPage;

use Espo\Core\Exceptions\BadRequest;
use Espo\Custom\Tools\Scoring\ScoringConditions;

/** Contextual public content never resolves a browser-supplied CRM identity. */
final class LandingPagePersonalization
{
    private const FIELDS = ['utm_source'=>['type'=>'varchar'], 'utm_medium'=>['type'=>'varchar'],
        'utm_campaign'=>['type'=>'varchar'], 'language'=>['type'=>'varchar'],
        'customer:lifecycleStage'=>['type'=>'varchar'], 'customer:leadStatus'=>['type'=>'varchar'],
        'customer:leadScore'=>['type'=>'int'], 'customer:segment'=>['type'=>'varchar'],
        'customer:visits30'=>['type'=>'int']];

    public static function normalize(mixed $input, string $type): ?array
    {
        if ($input === null) return null;
        if ($input instanceof \stdClass) $input = (array) $input;
        if (!is_array($input) || !in_array($type, ['hero','text','cta'], true) ||
            array_diff(array_keys($input), ['field','operator','value','heading','text']) ||
            !is_string($input['field'] ?? null) || !isset(self::FIELDS[$input['field']])) {
            throw new BadRequest('Choose a supported contextual content rule.');
        }
        foreach (['value'=>100,'heading'=>200,'text'=>2000] as $key=>$max) {
            if (!is_string($input[$key] ?? null) || mb_strlen($input[$key]) > $max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $input[$key])) {
                throw new BadRequest('Enter bounded content and a valid comparison value.');
            }
            $input[$key] = trim($input[$key]);
        }
        if ($input['value'] === '' || $input['heading'] === '') throw new BadRequest('Enter a comparison value and a variant heading.');
        $operator=$input['operator']??'equals';
        if(!in_array($operator,in_array($input['field'],['customer:leadScore','customer:visits30'],true)?['equals','greater_than','less_than']:['equals'],true))throw new BadRequest('Choose a supported variant comparison.');
        if(in_array($input['field'],['customer:leadScore','customer:visits30'],true) && (!preg_match('/^(0|[1-9][0-9]{0,6})$/D',$input['value']) || (int)$input['value']>1000000))throw new BadRequest('Enter a whole-number threshold from 0 to 1000000.');
        if($input['field']==='customer:visits30' && (int)$input['value']>9999)throw new BadRequest('Website visit thresholds must be from 0 to 9999.');
        if($input['field']==='customer:segment' && !preg_match('/^[a-zA-Z0-9]{1,24}$/D',$input['value']))throw new BadRequest('Choose a native audience in this workspace.');
        ScoringConditions::validate(['field'=>$input['field'],'operator'=>$operator,'value'=>$input['value']], self::FIELDS);
        return $input;
    }

    public static function decide(array $block, array $context, bool $privacyControl = false): array
    {
        $rule = self::normalize($block['personalization'] ?? null, (string) ($block['type'] ?? ''));
        if (!$rule) return ['block'=>$block, 'evidence'=>null];
        $customer=str_starts_with($rule['field'],'customer:');
        $raw = $customer ? null : ($context[$rule['field']] ?? null);
        $value = is_string($raw) && mb_strlen($raw) <= 100 ? trim($raw) : null;
        $blocked = $privacyControl && $rule['field'] !== 'language';
        $outcome = ScoringConditions::evaluate(['field'=>$rule['field'],'operator'=>$rule['operator']??'equals','value'=>$rule['value']],
            self::FIELDS, [$rule['field']=>$blocked ? null : $value]);
        if ($outcome['matched']) {
            $block['heading'] = $rule['heading'];
            $block['text'] = $rule['text'];
        }
        return ['block'=>$block, 'evidence'=>['blockId'=>$block['id'], 'field'=>$rule['field'],
            'matched'=>$outcome['matched'], 'branch'=>$outcome['matched'] ? 'variant' : 'default',
            'privacyControl'=>$blocked, 'inputHash'=>hash('sha256', json_encode($blocked ? null : $value)),
            'ruleHash'=>hash('sha256', json_encode($rule, JSON_THROW_ON_ERROR))]];
    }

    public static function customerDecision(array $block, mixed $value, bool $eligible, ?bool $behaviorMatch = null): array
    {
        $rule=self::normalize($block['personalization']??null,(string)($block['type']??''));
        if(!$rule || !str_starts_with($rule['field'],'customer:'))throw new BadRequest('Choose a known-customer variant.');
        $outcome=ScoringConditions::evaluate(['field'=>$rule['field'],'operator'=>$rule['operator']??'equals','value'=>$rule['value']],self::FIELDS,[$rule['field']=>$eligible?$value:null]);
        if($rule['field']==='customer:visits30' && $behaviorMatch!==null)$outcome['matched']=$eligible&&$behaviorMatch;
        if($outcome['matched']){$block['heading']=$rule['heading'];$block['text']=$rule['text'];}
        return ['block'=>$block,'evidence'=>['blockId'=>$block['id'],'field'=>$rule['field'],'matched'=>$outcome['matched'],
            'branch'=>$outcome['matched']?'variant':'default','privacyControl'=>false,
            'inputHash'=>hash('sha256',json_encode($eligible?$value:null)), 'ruleHash'=>hash('sha256',json_encode($rule,JSON_THROW_ON_ERROR))]];
    }
}
