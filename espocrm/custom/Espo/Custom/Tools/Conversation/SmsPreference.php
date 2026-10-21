<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Provider opt-in evidence never overrides CRM consent or a previous opt-out. */
final class SmsPreference
{
    public static function type(array $event): ?string
    {
        if(($event['kind']??null)!=='inbound'||!in_array($event['channel']??null,['sms','whatsapp'],true))return null;
        $type=$event['optOutType']??null;
        if($type!==null){
            if(!in_array($type,['STOP','START','HELP'],true))throw new BadRequest('Invalid provider opt-out type.');
            return $type;
        }
        return in_array(strtoupper(trim($event['body']??'')),['STOP','STOPALL','UNSUBSCRIBE','CANCEL','END','QUIT','REVOKE','OPTOUT'],true)?'STOP':null;
    }
    public static function peerHash(string $connection,string $peer): string
    {
        return hash('sha256',$connection."\0".$peer);
    }
}
