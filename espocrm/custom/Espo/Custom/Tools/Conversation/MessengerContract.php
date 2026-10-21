<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Exceptions\{BadRequest,Forbidden};

final class MessengerContract
{
    public static function id(mixed $id): string
    {
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,31}$/D',$id))throw new BadRequest('Enter a valid Meta Page, app or recipient ID.');return $id;
    }
    public static function text(mixed $text): string
    {
        if(!is_string($text)||!mb_check_encoding($text,'UTF-8')||trim($text)===''||mb_strlen($text)>2000||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text))throw new BadRequest('Enter a message of up to 2,000 characters.');return $text;
    }
    public static function challenge(string $query,string $expectedHash): string
    {
        $params=[];if(strlen($query)>2048)throw new BadRequest('Invalid Messenger challenge.');
        foreach(explode('&',$query) as $item){$pair=explode('=',$item,2);$key=urldecode($pair[0]);if(count($pair)!==2||isset($params[$key])||!in_array($key,['hub.mode','hub.verify_token','hub.challenge'],true))throw new BadRequest('Invalid Messenger challenge.');$params[$key]=urldecode($pair[1]);}
        if(($params['hub.mode']??null)!=='subscribe'||!hash_equals($expectedHash,hash('sha256',$params['hub.verify_token']??'')))throw new Forbidden('Messenger verification failed.');
        $challenge=$params['hub.challenge']??'';if(!preg_match('/^[0-9]{1,128}$/D',$challenge))throw new BadRequest('Invalid challenge value.');return $challenge;
    }
    public static function messages(string $raw,string $signature,#[\SensitiveParameter] string $secret,int $now): array
    {
        return array_values(array_filter(self::events($raw,$signature,$secret,$now),fn($event)=>!isset($event['kind'])));
    }
    public static function events(string $raw,string $signature,#[\SensitiveParameter] string $secret,int $now): array
    {
        if(strlen($raw)>262144||!preg_match('/^sha256=([a-f0-9]{64})$/D',$signature,$m)||!hash_equals(hash_hmac('sha256',$raw,$secret),$m[1]))throw new Forbidden('Invalid Messenger signature.');
        try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw new BadRequest('Invalid Messenger payload.');}
        if(!is_array($data)||($data['object']??null)!=='page'||!is_array($data['entry']??null)||!array_is_list($data['entry'])||count($data['entry'])>20)throw new BadRequest('Invalid Page webhook.');$result=[];$count=0;
        foreach($data['entry'] as $entry){$page=self::id($entry['id']??null);if(!array_key_exists('messaging',$entry))continue;if(!is_array($entry['messaging'])||!array_is_list($entry['messaging']))throw new BadRequest('Invalid Page events.');
            foreach($entry['messaging'] as $event){if(++$count>100)throw new BadRequest('Messenger event batch exceeds its limit.');
                if(isset($event['delivery'])||isset($event['read'])){
                    $peer=self::id($event['sender']['id']??null);if(self::id($event['recipient']['id']??null)!==$page||$peer===$page||isset($event['message'])||(isset($event['delivery'])&&isset($event['read'])))throw new Forbidden('Invalid receipt identity.');
                    $kind=isset($event['read'])?'read':'delivery';$status=$event[$kind];$watermark=self::time($status['watermark']??null,$now);$mids=$status['mids']??[];
                    if(!is_array($mids)||!array_is_list($mids)||count($mids)>100)throw new BadRequest('Invalid receipt message IDs.');foreach($mids as $mid)self::mid($mid);
                    $result[]=['kind'=>$kind,'page'=>$page,'peer'=>$peer,'watermark'=>$watermark,'mids'=>array_values(array_unique($mids))];continue;
                }
                if(!isset($event['message']))continue;
                if(($event['message']['is_echo']??false)===true){
                    if(self::id($event['sender']['id']??null)!==$page)throw new Forbidden('Invalid echo sender.');$peer=self::id($event['recipient']['id']??null);if($peer===$page)throw new Forbidden('Invalid echo recipient.');
                    $mid=self::mid($event['message']['mid']??null);$at=self::time($event['timestamp']??null,$now);$metadata=$event['message']['metadata']??null;
                    if($metadata!==null&&(!is_string($metadata)||strlen($metadata)>1000))throw new BadRequest('Invalid echo metadata.');
                    $result[]=['kind'=>'echo','page'=>$page,'peer'=>$peer,'messageId'=>$mid,'timestamp'=>$at,'metadata'=>$metadata];continue;
                }
                $peer=self::id($event['sender']['id']??null);if(self::id($event['recipient']['id']??null)!==$page||$peer===$page)throw new Forbidden('Messenger recipient does not match the Page.');
                $message=$event['message'];$mid=$message['mid']??null;$at=$event['timestamp']??null;
                if(!is_string($mid)||!preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/D',$mid)||!is_int($at)||$at<946684800000||$at>($now+60)*1000)throw new BadRequest('Invalid Messenger message identity or time.');
                $text=$message['text']??'';if($text!=='')self::text($text);$attachments=$message['attachments']??[];
                if(!is_array($attachments)||!array_is_list($attachments)||count($attachments)>10||($text===''&&!$attachments))throw new BadRequest('Invalid Messenger message.');
                $types=[];$media=[];foreach($attachments as $attachment){$type=$attachment['type']??null;if(!in_array($type,['image','audio','video','file','fallback','location'],true))throw new BadRequest('Unsupported Messenger attachment.');$types[]=$type;
                    if(in_array($type,['image','audio','video','file'],true)&&isset($attachment['payload']['url']))$media[]=['type'=>$type,'url'=>MessengerMediaFetcher::url($attachment['payload']['url'])];
                }
                $normalized=['page'=>$page,'peer'=>$peer,'messageId'=>$mid,'body'=>$text,'attachments'=>$types,'timestamp'=>$at];if($media)$normalized['media']=$media;$result[]=$normalized;
            }
        }return $result;
    }
    public static function window(int $timestamp,int $now): bool {return $timestamp<=($now+1)*1000&&$timestamp>($now-86340)*1000;}
    public static function time(mixed $value,int $now): int {if(!is_int($value)||$value<946684800000||$value>($now+60)*1000)throw new BadRequest('Invalid provider receipt time.');return $value;}
    public static function mid(mixed $value): string {if(!is_string($value)||!preg_match('/^[A-Za-z0-9_.:\-]{1,255}$/D',$value))throw new BadRequest('Invalid provider message ID.');return $value;}
    public static function date(int $value): string {return gmdate('Y-m-d H:i:s',intdiv($value,1000)).sprintf('.%03d',$value%1000);}
}
