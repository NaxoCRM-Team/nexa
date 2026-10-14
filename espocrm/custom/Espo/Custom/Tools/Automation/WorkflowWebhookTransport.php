<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Automation;

/** Secure transport. A timeout/5xx is uncertain, never proof that a POST was not accepted. */
class WorkflowWebhookTransport
{
    public function validateUrl(string $url): string
    {
        $parts=parse_url($url);$host=strtolower((string)($parts['host'] ?? ''));
        if(strlen($url)>512 || !filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme'] ?? '')!=='https' || isset($parts['user'],$parts['pass']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || ($parts['port'] ?? 443)!==443 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host) || preg_match('/[\x00-\x20\x7f]/',$url) || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D',$host))throw new \Espo\Core\Exceptions\BadRequest('Choose a public HTTPS webhook on port 443 without credentials or a fragment.');
        return $host;
    }

    public function send(string $url,string $payload,string $key,string $secret): array
    {
        $host=$this->validateUrl($url);$records=dns_get_record($host,DNS_A | DNS_AAAA);$ips=[];
        foreach($records ?: [] as $record)if(isset($record['ip']) || isset($record['ipv6'])) {
            $ip=$record['ip'] ?? $record['ipv6'];
            if(!$this->publicAddress($ip))return ['outcome'=>'failed','code'=>'address_blocked','status'=>null];
            $ips[]=$ip;
        }
        if(!$ips)return ['outcome'=>'not_sent','code'=>'dns_unavailable','status'=>null];
        $handle=curl_init($url);if($handle===false)return ['outcome'=>'not_sent','code'=>'transport_unavailable','status'=>null];
        $pin=str_contains($ips[0],':')?'['.$ips[0].']':$ips[0];$size=0;
        curl_setopt_array($handle,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Idempotency-Key: '.$key,'X-Nexa-Signature: '.hash_hmac('sha256',$payload,$secret)],CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT_MS=>1500,CURLOPT_TIMEOUT_MS=>3500,CURLOPT_RESOLVE=>[$host.':443:'.$pin],CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$size): int{$size+=strlen($chunk);return $size>65536?0:strlen($chunk);}]);
        $ok=curl_exec($handle);$error=curl_errno($handle);$status=(int)curl_getinfo($handle,CURLINFO_RESPONSE_CODE);curl_close($handle);
        if($ok===false){
            $safe=in_array($error,[CURLE_COULDNT_RESOLVE_HOST,CURLE_COULDNT_CONNECT,CURLE_SSL_CONNECT_ERROR,CURLE_PEER_FAILED_VERIFICATION],true);
            return ['outcome'=>$safe?'not_sent':'uncertain','code'=>$safe?'connection_unavailable':'submission_uncertain','status'=>$status ?: null];
        }
        return ['outcome'=>$status>=200 && $status<300?'success':($status>=500 || $status===408 || $status===429?'uncertain':'failed'),'code'=>$status>=200 && $status<300?null:'http_rejected','status'=>$status];
    }

    public function publicAddress(string $ip): bool
    {
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))return false;
        $bytes=inet_pton($ip);
        if(strlen($bytes)===4){
            $octets=array_values(unpack('C4',$bytes));
            return $octets[0]<224 && !($octets[0]===100 && $octets[1]>=64 && $octets[1]<=127) && !($octets[0]===198 && in_array($octets[1],[18,19],true));
        }
        // Only global unicast; exclude transition/tunnel and documentation networks.
        $hex=bin2hex($bytes);
        return ($hex[0]==='2' || $hex[0]==='3') && !str_starts_with($hex,'20010000') && !str_starts_with($hex,'20010db8') && !str_starts_with($hex,'2002');
    }
}
