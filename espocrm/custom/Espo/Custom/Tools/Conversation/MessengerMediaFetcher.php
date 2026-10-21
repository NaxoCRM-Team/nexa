<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\Exceptions\BadRequest;

/** Only signed Meta CDN references; no redirects, private IPs, proxy or credentials. */
class MessengerMediaFetcher
{
    public const MAX_BYTES=8*1024*1024;
    public static function url(mixed $url): string
    {
        if(!is_string($url)||strlen($url)>4096||preg_match('/[\x00-\x20\x7f\\\\]/',$url))throw new BadRequest('Invalid Messenger media reference.');$p=parse_url($url);$host=strtolower($p['host']??'');
        if(!$p||($p['scheme']??'')!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||(isset($p['port'])&&$p['port']!==443)||!preg_match('/^(?:[a-z0-9-]+\.)*(?:fbcdn\.net|fbsbx\.com)$/D',$host))throw new BadRequest('Unsupported Messenger media host.');return $url;
    }
    public function fetch(#[\SensitiveParameter] string $url): string
    {
        self::url($url);$host=parse_url($url,PHP_URL_HOST);$ips=$this->resolve($host);
        if(!$ips)throw new BadRequest('Messenger media DNS is unavailable.');
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)||preg_match('/^(?:100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.|198\.(?:18|19)\.|192\.0\.0\.)/',$ip))throw new BadRequest('Messenger media resolved to an unsafe address.');
        return $this->download($url,$host,$ips[0]);
    }
    protected function resolve(string $host): array {return gethostbynamel($host)?:[];}
    protected function download(#[\SensitiveParameter] string $url,string $host,string $ip): string
    {
        $curl=curl_init($url);if(!$curl)throw new BadRequest('Media download is unavailable.');$bytes='';
        curl_setopt_array($curl,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_IPRESOLVE=>CURL_IPRESOLVE_V4,CURLOPT_RESOLVE=>[$host.':443:'.$ip],CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>15,CURLOPT_HTTPHEADER=>['Accept: application/octet-stream'],CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$bytes):int{if(strlen($bytes)+strlen($chunk)>self::MAX_BYTES)return 0;$bytes.=$chunk;return strlen($chunk);}]);
        try{if(curl_exec($curl)===false||(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE)!==200||$bytes==='')throw new BadRequest('Messenger media could not be downloaded within its limit.');return $bytes;}finally{curl_close($curl);}
    }
    public static function inspect(string $bytes,string $kind): array
    {
        if($bytes===''||strlen($bytes)>self::MAX_BYTES)throw new BadRequest('Messenger files must be 8 MB or smaller.');
        $type=(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $types=['image/png'=>['image','png'],'image/jpeg'=>['image','jpg'],'image/gif'=>['image','gif'],'image/webp'=>['image','webp'],'audio/mpeg'=>['audio','mp3'],'audio/ogg'=>['audio','ogg'],'audio/x-wav'=>['audio','wav'],'audio/wav'=>['audio','wav'],'audio/mp4'=>['audio','m4a'],'video/mp4'=>['video','mp4'],'video/webm'=>['video','webm'],'application/pdf'=>['file','pdf'],'text/plain'=>['file','txt'],'application/zip'=>['file','zip']];
        $types['audio/x-m4a']=['audio','m4a'];$types['audio/webm']=['audio','webm'];
        if(!isset($types[$type])||$types[$type][0]!==$kind)throw new BadRequest('The file content does not match a supported Messenger media type.');
        if($kind==='image'){$image=@getimagesizefromstring($bytes);if(!$image||$image[0]>10000||$image[1]>10000||$image[0]*$image[1]>40000000)throw new BadRequest('The Messenger image dimensions are unsupported.');}
        return ['kind'=>$kind,'mimeType'=>$type,'name'=>'messenger-'.substr(hash('sha256',$bytes),0,12).'.'.$types[$type][1],'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes)];
    }
}
