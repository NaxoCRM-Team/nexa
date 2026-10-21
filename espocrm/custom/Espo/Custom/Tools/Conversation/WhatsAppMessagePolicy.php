<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use DateTimeImmutable;
use Espo\Core\Exceptions\{BadRequest,Forbidden};

/** Provider session evidence is not CRM consent. This policy never enables delivery. */
final class WhatsAppMessagePolicy
{
    public static function sessionOpen(?DateTimeImmutable $providerInboundAt,DateTimeImmutable $now): bool
    {
        if(!$providerInboundAt)return false;
        $age=$now->getTimestamp()-$providerInboundAt->getTimestamp();
        return $age>=0&&$age<86400-60;
    }
    public static function approvedText(array $content,array $approval,string $account,string $sid,array $variables): array
    {
        if(!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account)||!preg_match('/^HX[0-9a-fA-F]{32}$/D',$sid))throw new BadRequest('Invalid WhatsApp template identity.');
        if(($content['account_sid']??null)!==$account||($content['sid']??null)!==$sid||($approval['account_sid']??null)!==$account||($approval['sid']??null)!==$sid||($approval['whatsapp']['status']??null)!=='approved')throw new Forbidden('This WhatsApp template is not approved in the connected account.');
        $category=$approval['whatsapp']['category']??null;
        if(!in_array($category,['UTILITY','MARKETING'],true))throw new Forbidden('This composer supports approved utility and marketing templates only.');
        $types=$content['types']??null;$body=$types['twilio/text']['body']??null;
        if(!is_array($types)||array_keys($types)!==['twilio/text']||!is_string($body)||!self::text($body,1600)||!is_string($content['language']??null)||!preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z]{2,4})?$/D',$content['language']))throw new BadRequest('Choose a supported approved text template.');
        preg_match_all('/\{\{([1-9][0-9]?)\}\}/',$body,$matches);$keys=array_values(array_unique($matches[1]));sort($keys,SORT_STRING);$given=array_map('strval',array_keys($variables));sort($given,SORT_STRING);
        if(count($keys)>20||$keys!==$given||preg_match('/[{}]/',preg_replace('/\{\{[1-9][0-9]?\}\}/','',$body)))throw new BadRequest('Supply exactly the template variables, without changing the approved text.');
        $replacements=[];
        foreach($variables as $key=>$value){if(!is_string($value)||!self::text($value,500)||strpbrk($value,"{}\r\n")!==false)throw new BadRequest('Enter valid single-line template values.');$replacements['{{'.$key.'}}']=$value;}
        $rendered=strtr($body,$replacements);if(!self::text($rendered,1600))throw new BadRequest('The rendered template exceeds the message limit.');
        return ['contentSid'=>$sid,'body'=>$rendered,'variables'=>$variables,'category'=>$category,'language'=>$content['language']];
    }
    private static function text(string $value,int $max): bool
    {
        return mb_check_encoding($value,'UTF-8')&&trim($value)!==''&&mb_strlen($value)<=$max&&!preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value);
    }
}
