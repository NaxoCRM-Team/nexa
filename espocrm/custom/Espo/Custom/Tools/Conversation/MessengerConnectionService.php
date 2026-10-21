<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;
use Espo\Core\{Acl,InjectableFactory};
use Espo\Core\Exceptions\{BadRequest,Conflict,Forbidden,NotFound};
use Espo\Core\Utils\Config;
use Espo\Custom\Tools\Auth\ProviderSecretCipher;
use Espo\Entities\User;
use stdClass;

final class MessengerConnectionService
{
    public function __construct(private ChatStore $store,private User $user,private Acl $acl,private InjectableFactory $factory,private ProviderSecretCipher $cipher,private MessengerClient $client,private Config $config) {}
    private function admin(): void {$this->factory->createWith(MessagingConnectionService::class,['user'=>$this->user,'acl'=>$this->acl])->process((object)['op'=>'list']);}
    public function process(stdClass $input): array
    {
        $this->admin();if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>10000)throw new BadRequest('Messenger settings exceed their limit.');
        return match($input->op??''){'list'=>$this->listing(),'save'=>$this->save($input),'verify'=>$this->verify($input),'activate'=>$this->activate($input),default=>throw new BadRequest('Choose a Messenger connection operation.')};
    }
    private function listing(): array
    {
        $rows=$this->store->rows("SELECT id,name,sender,messenger_app_id,status,version_number,dispatch_enabled,verified_at,messenger_webhook_verified_at FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND provider='meta' AND channel='messenger' ORDER BY created_at,id LIMIT 20",$this->store->scope());
        foreach($rows as &$row){try{$row['callbackUrl']=$this->callbackUrl($row['id']);}catch(Forbidden $e){$row['callbackUrl']=null;}}
        return ['list'=>$rows,'activationAvailable'=>$this->config->get('nexaMessengerDispatchEnabled')===true];
    }
    public function connection(string $id,bool $lock=false): array
    {
        ChatStore::uuidValue($id);$row=$this->store->rows("SELECT * FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? AND id=? AND provider='meta' AND channel='messenger'".($lock?' FOR UPDATE':''),[...$this->store->scope(),$id])[0]??null;
        if(!$row)throw new NotFound('Messenger connection unavailable.');return $row;
    }
    public function callbackUrl(string $id): string
    {
        ChatStore::uuidValue($id);$base=$this->config->get('nexaMessagingPublicBaseUrl');$p=is_string($base)?parse_url($base):false;
        if(!$p||($p['scheme']??null)!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||strlen($base)>1500||preg_match('/[\x00-\x20\x7f\\\\]/',$base))throw new Forbidden('Public HTTPS messaging callbacks are not configured.');
        return rtrim($base,'/').'/api/v1/Nexa/public/messenger/'.$id;
    }
    private function save(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','version','name','pageId','appId','pageToken','appSecret','verifyToken','disabled'])||!is_string($in->name??null)||trim($in->name)===''||mb_strlen($in->name)>120||preg_match('/[\x00-\x1f\x7f]/',$in->name)||!is_bool($in->disabled??null))throw new BadRequest('Complete the Messenger connection settings.');
        MessengerContract::id($in->pageId??null);MessengerContract::id($in->appId??null);
        foreach(['pageToken','appSecret','verifyToken'] as $key)if(isset($in->$key)&&(!is_string($in->$key)||!preg_match($key==='appSecret'?'/^[a-fA-F0-9]{32}$/D':'/^[A-Za-z0-9_\-]{32,2048}$/D',$in->$key)))throw new BadRequest('Enter valid Messenger credentials and a strong verification token.');
        return $this->store->atomic(function()use($in):array{
            $this->admin();$id=$in->id??null;$old=null;if($id!==null){$old=$this->connection($id,true);if(!is_int($in->version??null)||(int)$old['version_number']!==$in->version)throw new Conflict('The Messenger connection changed.');if($old['sender']!==$in->pageId||$old['messenger_app_id']!==$in->appId)throw new BadRequest('Create a separate connection for a different Page or app.');}
            else{if(!isset($in->pageToken,$in->appSecret,$in->verifyToken))throw new BadRequest('Page token, app secret and verification token are required.');if(count($this->store->rows('SELECT id FROM nexa_messaging_connection WHERE tenant_id=? AND service_id=? LIMIT 20',$this->store->scope()))>=20)throw new BadRequest('The workspace connection limit has been reached.');$id=ChatStore::uuid();}
            [$tenant,$service]=$this->store->scope();$changed=isset($in->pageToken)||isset($in->appSecret)||isset($in->verifyToken);$status=$in->disabled?'disabled':(!$changed&&($old['status']??'')==='verified'?'verified':'unverified');
            $token=isset($in->pageToken)?$this->cipher->encryptFor($in->pageToken,$tenant,$service,'messaging:'.$id):$old['secret_encrypted'];$secret=isset($in->appSecret)?$this->cipher->encryptFor($in->appSecret,$tenant,$service,'messenger-app:'.$id):$old['messenger_app_secret'];$hash=isset($in->verifyToken)?hash('sha256',$in->verifyToken):$old['messenger_verify_hash'];
            if(!$old)$this->store->execute("INSERT INTO nexa_messaging_connection (id,tenant_id,service_id,name,provider,channel,account_sid,sender,secret_encrypted,messenger_app_id,messenger_app_secret,messenger_verify_hash,status,created_by_id,created_at,modified_at) VALUES (?,?,?,?,'meta','messenger',?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP())",[$id,$tenant,$service,$in->name,$in->pageId,$in->pageId,$token,$in->appId,$secret,$hash,$status,$this->user->getId()]);
            else $this->store->execute("UPDATE nexa_messaging_connection SET name=?,secret_encrypted=?,messenger_app_secret=?,messenger_verify_hash=?,status=?,verified_at=?,provider_resource_sid=?,dispatch_enabled=IF(?='verified',dispatch_enabled,0),messenger_webhook_verified_at=IF(?,NULL,messenger_webhook_verified_at),version_number=version_number+1,modified_at=UTC_TIMESTAMP() WHERE tenant_id=? AND service_id=? AND id=?",[$in->name,$token,$secret,$hash,$status,$status==='verified'?$old['verified_at']:null,$status==='verified'?$old['provider_resource_sid']:null,$status,(int)$changed,...$this->store->scope(),$id]);
            $this->audit($id,'messenger.connection_saved',['status'=>$status]);return ['id'=>$id];
        },true);
    }
    public function secrets(array $row): array
    {
        return [$this->cipher->decryptFor($row['secret_encrypted'],$row['tenant_id'],$row['service_id'],'messaging:'.$row['id']),$this->cipher->decryptFor($row['messenger_app_secret'],$row['tenant_id'],$row['service_id'],'messenger-app:'.$row['id'])];
    }
    private function verify(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','version'])||!is_string($in->id??null)||!is_int($in->version??null))throw new BadRequest('Provide the current connection revision.');$row=$this->connection($in->id);
        if((int)$row['version_number']!==$in->version||$row['status']==='disabled')throw new Conflict('Refresh the active Messenger connection.');
        [$token,$secret]=$this->secrets($row);
        try{$this->client->verify($row['sender'],$row['messenger_app_id'],$token,$secret);}catch(Forbidden $e){$this->store->atomic(function()use($in):void{$current=$this->connection($in->id,true);if((int)$current['version_number']===$in->version){$this->store->execute("UPDATE nexa_messaging_connection SET status='unverified',dispatch_enabled=0,provider_resource_sid=NULL,verified_at=NULL,version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$in->id]);$this->audit($in->id,'messenger.proof_revoked',[]);}},true);throw $e;}
        return $this->store->atomic(function()use($in,$row):array{
            $this->admin();$current=$this->connection($in->id,true);if((int)$current['version_number']!==$in->version||$current['status']==='disabled')throw new Conflict('Messenger settings changed during verification.');
            try{$this->store->execute("UPDATE nexa_messaging_connection SET status='verified',provider_resource_sid=sender,verified_at=UTC_TIMESTAMP(),version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?",[...$this->store->scope(),$in->id]);}catch(\PDOException $e){if(($e->errorInfo[1]??null)===1062)throw new Conflict('This verified Page is already assigned.');throw $e;}
            $this->audit($in->id,'messenger.connection_verified',[]);return ['id'=>$row['id']];
        },true);
    }
    private function activate(stdClass $in): array
    {
        if(array_diff(array_keys((array)$in),['op','id','version','enabled'])||!is_string($in->id??null)||!is_int($in->version??null)||!is_bool($in->enabled??null))throw new BadRequest('Provide the current reply setting.');
        return $this->store->atomic(function()use($in):array{
            $this->admin();$row=$this->connection($in->id,true);if((int)$row['version_number']!==$in->version)throw new Conflict('Messenger settings changed.');
            if($in->enabled&&(!$this->enabled($row,false)||!$row['messenger_webhook_verified_at']))throw new Forbidden('Deployment approval, fresh Page proof and verified webhook are required.');
            $this->store->execute('UPDATE nexa_messaging_connection SET dispatch_enabled=?,version_number=version_number+1 WHERE tenant_id=? AND service_id=? AND id=?',[(int)$in->enabled,...$this->store->scope(),$in->id]);$this->audit($in->id,'messenger.reply_activation_changed',['enabled'=>$in->enabled]);return ['id'=>$in->id];
        },true);
    }
    public function enabled(array $row,bool $activation=true): bool
    {
        if($this->config->get('nexaMessengerDispatchEnabled')!==true||$row['status']!=='verified'||!$row['verified_at']||strtotime($row['verified_at'].' UTC')<time()-86400||($activation&&(!$row['dispatch_enabled']||!$row['messenger_webhook_verified_at'])))return false;
        try{$this->callbackUrl($row['id']);return true;}catch(Forbidden $e){return false;}
    }
    public function audit(string $id,string $action,array $metadata): void
    {
        $this->store->execute("INSERT INTO nexa_audit_event (id,tenant_id,service_id,actor_type,actor_user_id,action,subject_type,subject_id,correlation_id,source,metadata_json,occurred_at) VALUES (?,?,?,'user',?,?,'Messenger',?,?,'messenger',?,UTC_TIMESTAMP(6))",[ChatStore::uuid(),...$this->store->scope(),$this->user->getId(),$action,$id,ChatStore::uuid(),json_encode($metadata,JSON_THROW_ON_ERROR)]);
    }
}
