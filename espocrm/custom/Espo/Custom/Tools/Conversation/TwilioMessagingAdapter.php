<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\{BadRequest, Forbidden};

/** Provider contract only: no credentials, network calls or CRM persistence. */
final class TwilioMessagingAdapter
{
    public function outbound(string $account, string $channel, string $sender, string $recipient, string $body, string $callback): array
    {
        $this->sid($account, 'AC');
        $this->address($channel, $sender); $this->address($channel, $recipient);
        $this->text($body, 1600);
        $this->https($callback);
        return ['method'=>'POST', 'url'=>'https://api.twilio.com/2010-04-01/Accounts/'.$account.'/Messages.json',
            'contentType'=>'application/x-www-form-urlencoded',
            'body'=>http_build_query(['From'=>$sender,'To'=>$recipient,'Body'=>$body,'StatusCallback'=>$callback], '', '&', PHP_QUERY_RFC3986)];
    }

    /** All parameters must be included, unchanged, after strict form parsing. */
    public function webhook(string $url, array $params, string $signature, string $secret, string $account, string $channel, string $sender, string $kind): array
    {
        $this->https($url);
        if (count($params)>100) throw new BadRequest('Messaging callback exceeds its limit.');
        $bytes=0;
        foreach ($params as $key=>$value) {
            if (!is_string($key)||!is_string($value)) throw new BadRequest('Messaging callbacks require flat string form fields.');
            $bytes+=strlen($key)+strlen($value);
            if ($bytes>65536) throw new BadRequest('Messaging callback exceeds its limit.');
        }
        if (!TwilioWebhookSignature::verify($url,$params,$signature,$secret)) throw new Forbidden('Invalid messaging callback signature.');
        $this->sid($account,'AC'); $this->address($channel,$sender);
        if (($params['AccountSid']??null)!==$account) throw new Forbidden('Messaging account does not match this connection.');
        if (!in_array($kind,['inbound','status'],true)) throw new BadRequest('Choose a messaging callback type.');
        $id=$params['MessageSid']??''; $this->sid($id,'(?:SM|MM)');
        if (isset($params['SmsSid']) && $params['SmsSid']!==$id) throw new BadRequest('Conflicting message identifiers.');
        $from=$params['From']??''; $to=$params['To']??'';
        $this->address($channel,$from); $this->address($channel,$to);
        if (($kind==='inbound'?$to:$from)!==$sender) throw new Forbidden('Messaging sender does not match this connection.');
        if ($kind==='status') {
            $status=$params['MessageStatus']??'';
            if (!in_array($status,['accepted','scheduled','queued','sending','sent','delivered','undelivered','failed','canceled','read'],true) || ($status==='read' && $channel!=='whatsapp')) throw new BadRequest('Unsupported message delivery status.');
            if (isset($params['SmsStatus']) && $params['SmsStatus']!==$status) throw new BadRequest('Conflicting message statuses.');
            $error=$params['ErrorCode']??'';
            if ($error!=='' && !preg_match('/^[0-9]{1,10}$/D',$error)) throw new BadRequest('Invalid message error code.');
            return ['provider'=>'twilio','channel'=>$channel,'kind'=>'status','messageId'=>$id,'status'=>$status,'errorCode'=>$error?:null,'from'=>$from,'to'=>$to];
        }
        $count=$params['NumMedia']??'0';
        if (!preg_match('/^(?:[0-9]|10)$/D',$count)) throw new BadRequest('Unsupported media count.');
        $body=$params['Body']??'';
        if ($body!=='' || $count==='0') $this->text($body,1600);
        $media=[];
        for ($i=0;$i<(int)$count;$i++) {
            $mediaUrl=$params['MediaUrl'.$i]??''; $this->https($mediaUrl);
            if (!in_array(strtolower((string)parse_url($mediaUrl,PHP_URL_HOST)),['api.twilio.com','api.us1.twilio.com','api.ie1.twilio.com','api.au1.twilio.com'],true)
                || parse_url($mediaUrl,PHP_URL_PATH)!=='/2010-04-01/Accounts/'.$account.'/Messages/'.$id.'/Media/'.basename((string)parse_url($mediaUrl,PHP_URL_PATH))
                || !preg_match('/^ME[0-9a-fA-F]{32}$/D',basename((string)parse_url($mediaUrl,PHP_URL_PATH)))) throw new BadRequest('Invalid provider media reference.');
            $mime=$params['MediaContentType'.$i]??'';
            if (!preg_match('/^[a-zA-Z0-9.+-]+\/[a-zA-Z0-9.+-]{1,100}$/D',$mime)) throw new BadRequest('Invalid media content type.');
            $media[]=['url'=>$mediaUrl,'contentType'=>strtolower($mime)];
        }
        $event=['provider'=>'twilio','channel'=>$channel,'kind'=>'inbound','messageId'=>$id,'from'=>$from,'to'=>$to,'body'=>$body,'media'=>$media];
        if(in_array($channel,['sms','whatsapp'],true)&&isset($params['OptOutType'])){
            $event['optOutType']=$params['OptOutType'];SmsPreference::type($event);
        }
        return $event;
    }

    private function sid(string $value, string $prefix): void
    {
        if (!preg_match('/^'.$prefix.'[0-9a-fA-F]{32}$/D',$value)) throw new BadRequest('Invalid provider identifier.');
    }
    private function address(string $channel, string $value): void
    {
        if (!in_array($channel,['sms','whatsapp'],true) || !preg_match($channel==='whatsapp'?'/^whatsapp:\+[1-9][0-9]{1,14}$/D':'/^\+[1-9][0-9]{1,14}$/D',$value)) throw new BadRequest('Use a valid channel address.');
    }
    private function text(string $value, int $max): void
    {
        if (!mb_check_encoding($value,'UTF-8') || trim($value)==='' || mb_strlen($value)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value)) throw new BadRequest('Enter valid message text within the channel limit.');
    }
    private function https(string $value): void
    {
        if (strlen($value)>2048 || !filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https' || parse_url($value,PHP_URL_USER)!==null || parse_url($value,PHP_URL_PASS)!==null || parse_url($value,PHP_URL_FRAGMENT)!==null || preg_match('/[\x00-\x20\x7f]/',$value)) throw new BadRequest('Use a canonical HTTPS URL without credentials or fragments.');
    }
}
