<?php
declare(strict_types=1);

/** Website-backend example only. Never serve this file as a browser script. */
final class NexaPersonalizationBackendExample
{
    private string $baseUrl;

    public function __construct(string $apiBaseUrl, private string $apiKey)
    {
        $url=parse_url($apiBaseUrl);
        $local=in_array(strtolower($url['host']??''),['localhost','127.0.0.1','[::1]'],true);
        if (!$url || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) ||
            !in_array($url['scheme']??'',['http','https'],true) || (($url['scheme']??'')!=='https' && !$local) ||
            !str_ends_with(rtrim($url['path']??'','/'),'/api/v1') || $apiKey==='' || preg_match('/[\r\n]/',$apiKey)) {
            throw new InvalidArgumentException('Configure a trusted Nexa API base URL and server-side API key.');
        }
        $this->baseUrl=rtrim($apiBaseUrl,'/');
    }

    /** Call only after the website verifies its session and maps it to an authorized Contact. */
    public function identify(string $visitorId,string $verifiedContactId,string $opaqueSessionReference,bool $gpc=false): array
    {
        return $this->request('identify',['visitorKey'=>$visitorId,'contactId'=>$verifiedContactId,
            'method'=>'authenticated_session','reference'=>$opaqueSessionReference,'privacyControl'=>$gpc]);
    }

    public function resolve(string $pageId,string $visitorId,bool $gpc=false): array
    {
        return $this->request('resolve',['pageId'=>$pageId,'visitorKey'=>$visitorId,'privacyControl'=>$gpc]);
    }

    /** Revoke before destroying the website session on logout. */
    public function revoke(string $visitorId): array
    {
        return $this->request('identity/revoke',['visitorKey'=>$visitorId]);
    }

    private function request(string $operation,array $body): array
    {
        if (!extension_loaded('curl')) throw new RuntimeException('The website backend requires PHP cURL.');
        $curl=curl_init($this->baseUrl.'/Nexa/personalization/'.$operation);
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,
            CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-Api-Key: '.$this->apiKey],
            CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR)]);
        try {
            $response=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if ($response===false || $status<200 || $status>=300) {
                // Do not expose upstream responses, credentials or session evidence to visitors.
                throw new RuntimeException('Customer personalization is temporarily unavailable.', $status);
            }
            $decoded=json_decode($response,true,32,JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) throw new RuntimeException('Unexpected personalization response.');
            return $decoded;
        } finally {curl_close($curl);}
    }
}
