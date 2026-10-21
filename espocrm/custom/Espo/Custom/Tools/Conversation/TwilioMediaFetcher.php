<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Exceptions\BadRequest;

/** Exact signed resource identity; pinned public DNS; credentials never follow redirects. */
class TwilioMediaFetcher
{
    public const MAX_BYTES=MessengerMediaFetcher::MAX_BYTES;
    public static function url(string $url,string $account,string $message): string
    {
        $p=self::parts($url);
        if(!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account)||!preg_match('/^(?:SM|MM)[0-9a-fA-F]{32}$/D',$message)||
            !in_array(strtolower($p['host']),['api.twilio.com','api.us1.twilio.com','api.ie1.twilio.com','api.au1.twilio.com'],true)||isset($p['query'])||
            !preg_match('~^/2010-04-01/Accounts/'.preg_quote($account,'~').'/Messages/'.preg_quote($message,'~').'/Media/ME[0-9a-fA-F]{32}$~D',$p['path']??''))throw new BadRequest('Invalid provider media reference.');
        return $url;
    }
    private static function parts(string $url): array
    {
        if(strlen($url)>4096||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new BadRequest('Invalid media reference.');$p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||(isset($p['port'])&&$p['port']!==443))throw new BadRequest('Invalid media reference.');return $p;
    }
    public static function cdn(string $url): string
    {
        $p=self::parts($url);if(strtolower($p['host'])!=='mms.twiliocdn.com')throw new BadRequest('Unsupported media redirect.');return $url;
    }
    public static function publicIp(string $ip): bool
    {
        return (bool)filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)&&!preg_match('/^(?:100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|198\.(?:18|19)\.|192\.0\.0\.)/',$ip);
    }
    public function fetch(string $url,string $account,string $message,#[\SensitiveParameter] string $secret): string
    {
        self::url($url,$account,$message);$response=$this->request($url,$account,$secret);
        if(in_array($response['status'],[301,302,303,307,308],true))$response=$this->request(self::cdn($response['location']),null,null);
        if($response['status']!==200||$response['body']==='')throw new BadRequest('Media download unavailable.');return $response['body'];
    }
    protected function resolve(string $host): array {return gethostbynamel($host)?:[];}
    protected function request(#[\SensitiveParameter] string $url,?string $account,#[\SensitiveParameter] ?string $secret): array
    {
        $host=parse_url($url,PHP_URL_HOST);$ips=$this->resolve($host);
        if(!$ips)throw new BadRequest('Media DNS unavailable.');foreach($ips as $ip)if(!self::publicIp($ip))throw new BadRequest('Unsafe media address.');
        $curl=curl_init($url);if(!$curl)throw new BadRequest('Media download unavailable.');$body='';$location='';$headerBytes=0;
        $options=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_RESOLVE=>[$host.':443:'.$ips[0]],CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/octet-stream'],
            CURLOPT_HEADERFUNCTION=>static function($handle,string $line)use(&$location,&$headerBytes):int{$headerBytes+=strlen($line);if($headerBytes>32768)return 0;if(stripos($line,'Location:')===0)$location=trim(substr($line,9));return strlen($line);},
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$body):int{if(strlen($body)+strlen($chunk)>self::MAX_BYTES)return 0;$body.=$chunk;return strlen($chunk);}];
        if($account!==null){$options[CURLOPT_HTTPAUTH]=CURLAUTH_BASIC;$options[CURLOPT_USERPWD]=$account.':'.$secret;}
        curl_setopt_array($curl,$options);
        try{if(curl_exec($curl)===false)throw new BadRequest('Media download exceeded its limits.');return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'location'=>$location,'body'=>$body];}finally{curl_close($curl);}
    }
    public static function kind(string $mime): string
    {
        return str_starts_with($mime,'image/')?'image':(str_starts_with($mime,'audio/')?'audio':(str_starts_with($mime,'video/')?'video':'file'));
    }
    public static function inspect(string $bytes,string $declared): array
    {
        $info=MessengerMediaFetcher::inspect($bytes,self::kind($declared));
        $canonical=static fn(string $mime)=>['audio/x-wav'=>'audio/wav','audio/x-m4a'=>'audio/mp4'][$mime]??$mime;
        if($canonical($info['mimeType'])!==$canonical($declared))throw new BadRequest('Media content does not match its declared type.');
        $info['name']=str_replace('messenger-','received-',$info['name']);return $info;
    }
}
