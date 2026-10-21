<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

/** A POST transport failure is uncertain, never proof that no SMS was created. */
class TwilioSmsTransport
{
    public function send(array $connection,#[\SensitiveParameter] string $secret,array $message,string $callback): array
    {
        $request=(new TwilioMessagingAdapter())->outbound($connection['account_sid'],$connection['channel']??'sms',$connection['sender'],$message['recipient'],$message['body'],$callback);
        if(($connection['channel']??'sms')==='whatsapp'&&isset($message['contentSid'])){
            if(!preg_match('/^HX[0-9a-fA-F]{32}$/D',$message['contentSid']))throw new \InvalidArgumentException('Invalid template SID.');
            parse_str($request['body'],$fields);unset($fields['Body']);$fields['ContentSid']=$message['contentSid'];
            $fields['ContentVariables']=json_encode((object)$message['variables'],JSON_THROW_ON_ERROR);
            $request['body']=http_build_query($fields,'','&',PHP_QUERY_RFC3986);
        }
        $response=$this->request('POST',$request['url'],$connection['account_sid'],$secret,$request['body']);
        if($response['status']===429)return ['outcome'=>'retry','error'=>'provider_rate_limit'];
        if(in_array($response['status'],[400,401,403,404,405,413,422],true))return ['outcome'=>'failed','error'=>'provider_rejected'];
        $data=$response['data'];
        if($response['status']===201&&$this->matches($data,$connection,$message)&&in_array($data['status']??null,['accepted','queued','sending','sent','delivered','failed','undelivered','read'],true))return ['outcome'=>'accepted','sid'=>$data['sid'],'status'=>$data['status']];
        return ['outcome'=>'uncertain','error'=>'provider_result_uncertain'];
    }
    public function inspect(array $connection,#[\SensitiveParameter] string $secret,string $sid,array $message): ?array
    {
        if(!preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D',$sid))return null;
        $response=$this->request('GET','https://api.twilio.com/2010-04-01/Accounts/'.$connection['account_sid'].'/Messages/'.$sid.'.json',$connection['account_sid'],$secret);
        $data=$response['data'];
        if($response['status']!==200||!$this->matches($data,$connection,$message)||$data['sid']!==$sid||!in_array($data['status']??null,['accepted','queued','sending','sent','delivered','failed','undelivered','canceled','read'],true))return null;
        return ['sid'=>$sid,'status'=>$data['status']];
    }
    private function matches(?array $data,array $connection,array $message): bool
    {
        return $data!==null&&preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D',$data['sid']??'')&&($data['account_sid']??null)===$connection['account_sid']&&($data['from']??null)===$connection['sender']&&($data['to']??null)===$message['recipient']&&($data['body']??null)===$message['body'];
    }
    protected function request(string $method,string $url,string $account,#[\SensitiveParameter] string $secret,?string $body=null): array
    {
        if(!function_exists('curl_init'))return ['status'=>0,'data'=>null];
        $curl=curl_init($url);if($curl===false)return ['status'=>0,'data'=>null];$response='';
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>10,CURLOPT_USERPWD=>$account.':'.$secret,CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>262144)return 0;$response.=$chunk;return strlen($chunk);}]);
        if($method==='POST')curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body]);else curl_setopt($curl,CURLOPT_HTTPGET,true);
        try{
            $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$data=null;
            if($ok===false)return ['status'=>0,'data'=>null];
            try{$decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);if(is_array($decoded)&&!array_is_list($decoded))$data=$decoded;}catch(\JsonException $error){}
            return ['status'=>$status,'data'=>$data];
        }finally{curl_close($curl);}
    }
}
