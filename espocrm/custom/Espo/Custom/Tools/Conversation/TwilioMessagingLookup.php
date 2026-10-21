<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest,Forbidden};

/** Read-only provider lookup. Never sends messages or changes provider configuration. */
class TwilioMessagingLookup
{
    public function proveSms(string $account,#[\SensitiveParameter] string $secret,string $sender): array
    {
        if (!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account) || !preg_match('/^[0-9a-fA-F]{32}$/D',$secret) || !preg_match('/^\+[1-9][0-9]{1,14}$/D',$sender)) throw new BadRequest('Invalid SMS provider settings.');
        $root='/2010-04-01/Accounts/'.$account;
        $record=$this->fetch($account,$secret,$root.'.json');
        if (($record['sid']??null)!==$account || ($record['status']??null)!=='active') throw new Forbidden('The provider account is not active or does not match.');
        $result=$this->fetch($account,$secret,$root.'/IncomingPhoneNumbers.json?'.http_build_query(['PhoneNumber'=>$sender,'PageSize'=>2],'','&',PHP_QUERY_RFC3986));
        $numbers=$result['incoming_phone_numbers']??null;
        if (!is_array($numbers) || !array_is_list($numbers) || count($numbers)>2) throw new BadRequest('Unexpected provider phone-number response.');
        foreach ($numbers as $number) {
            if (!is_array($number) || ($number['account_sid']??null)!==$account || ($number['phone_number']??null)!==$sender || ($number['capabilities']['sms']??null)!==true) continue;
            $sid=$number['sid']??'';
            if (is_string($sid)&&preg_match('/^PN[0-9a-fA-F]{32}$/D',$sid)) return ['phoneSid'=>$sid];
        }
        throw new Forbidden('The account does not own this SMS-capable sender.');
    }
    protected function fetch(string $account,#[\SensitiveParameter] string $secret,string $path): array
    {
        return $this->fetchAt('api.twilio.com',$account,$secret,$path);
    }
    public function proveWhatsApp(string $account,#[\SensitiveParameter] string $secret,string $sender,string $sid): array
    {
        if(!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account)||!preg_match('/^[0-9a-fA-F]{32}$/D',$secret)||!preg_match('/^whatsapp:\+[1-9][0-9]{1,14}$/D',$sender)||!preg_match('/^XE[0-9a-fA-F]{32}$/D',$sid))throw new BadRequest('Enter the WhatsApp sender and its XE sender SID.');
        $record=$this->fetch($account,$secret,'/2010-04-01/Accounts/'.$account.'.json');
        if(($record['sid']??null)!==$account||($record['status']??null)!=='active')throw new Forbidden('The provider account is not active or does not match.');
        $proof=$this->fetchChannel($account,$secret,$sid);
        if(($proof['sid']??null)!==$sid||($proof['sender_id']??null)!==$sender||($proof['status']??null)!=='ONLINE')throw new Forbidden('The WhatsApp sender is not approved and online in this account.');
        return ['phoneSid'=>$sid];
    }
    protected function fetchChannel(string $account,#[\SensitiveParameter] string $secret,string $sid): array
    {
        return $this->fetchAt('messaging.twilio.com',$account,$secret,'/v2/Channels/Senders/'.$sid);
    }
    protected function fetchAt(string $host,string $account,#[\SensitiveParameter] string $secret,string $path): array
    {
        if(!in_array($host,['api.twilio.com','messaging.twilio.com','content.twilio.com'],true)||!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new BadRequest('Invalid provider endpoint.');
        if (!function_exists('curl_init')) throw new BadRequest('Provider verification requires the server cURL extension.');
        $curl=curl_init('https://'.$host.$path);
        if ($curl===false) throw new BadRequest('Provider verification is unavailable.');
        $body='';
        curl_setopt_array($curl,[CURLOPT_HTTPGET=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,
            CURLOPT_USERPWD=>$account.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,CURLOPT_HTTPHEADER=>['Accept: application/json'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>262144)return 0;$body.=$chunk;return strlen($chunk);}]);
        try {
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($ok===false) throw new BadRequest('Provider verification timed out or was unavailable. No message was sent.');
            if (in_array($status,[401,403],true)) throw new Forbidden('Provider credentials could not be verified.');
            if ($status!==200) throw new BadRequest('Provider verification is unavailable. Try again later.');
            try {$data=json_decode($body,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $error){throw new BadRequest('Unexpected provider verification response.');}
            if(!is_array($data)||array_is_list($data))throw new BadRequest('Unexpected provider verification response.');
            return $data;
        } finally {curl_close($curl);}
    }
}
