<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use DateTimeImmutable;
use Espo\Core\Exceptions\{BadRequest,Forbidden};

/** Delayed receipt arrival is never used as the start of a customer-service window. */
class WhatsAppSessionLookup extends TwilioMessagingLookup
{
    public function inspect(string $account,#[\SensitiveParameter] string $secret,string $sender,string $peer,string $messageSid,DateTimeImmutable $now): array
    {
        if(!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account)||!preg_match('/^[0-9a-fA-F]{32}$/D',$secret)||!preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D',$messageSid))throw new BadRequest('Invalid WhatsApp session identity.');
        foreach([$sender,$peer] as $address)if(!preg_match('/^whatsapp:\+[1-9][0-9]{1,14}$/D',$address))throw new BadRequest('Invalid WhatsApp session address.');
        $record=$this->fetch($account,$secret,'/2010-04-01/Accounts/'.$account.'/Messages/'.$messageSid.'.json');
        if(($record['account_sid']??null)!==$account||($record['sid']??null)!==$messageSid||($record['from']??null)!==$peer||($record['to']??null)!==$sender||($record['direction']??null)!=='inbound'||($record['status']??null)!=='received')throw new Forbidden('Provider message does not prove this WhatsApp customer-service session.');
        $date=$record['date_created']??null;
        $at=is_string($date)&&strlen($date)<=64&&!preg_match('/[\x00-\x1f\x7f]/',$date)?DateTimeImmutable::createFromFormat(DATE_RFC2822,$date):false;
        if(!$at||$at->format(DATE_RFC2822)!==$date||$at>$now)throw new Forbidden('Provider inbound time could not be verified.');
        return ['inboundAt'=>$at->format(DATE_ATOM),'open'=>WhatsAppMessagePolicy::sessionOpen($at,$now)];
    }
}
