<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use DateTimeImmutable;
use Espo\Core\Exceptions\{BadRequest,Forbidden};
use Espo\Custom\Tools\Auth\ProviderSecretCipher;

/** Provider reads happen before queue admission, never inside a database lock. */
final class WhatsAppDispatchPolicy
{
    public function __construct(private ChatStore $store,private ProviderSecretCipher $cipher,
        private WhatsAppSessionLookup $sessions,private WhatsAppTemplateLookup $templates) {}

    public function inspect(array $check,array $message): array
    {
        $c=$check['connection'];$peer=$check['recipient'];
        $secret=$this->cipher->decryptFor($c['secret_encrypted'],...[$this->store->scope()[0],$this->store->scope()[1],'messaging:'.$c['id']]);
        $proof=['connectionVersion'=>(int)$c['version_number'],'checkedAt'=>time(),'recipient'=>$peer,
            'scope'=>$this->store->scope(),'connectionId'=>$c['id'],'conversationId'=>$check['root']->getId(),
            'purposeId'=>$check['purpose']['id'],'consentEventId'=>$check['purpose']['source_event_id']];
        if(($message['contentSid']??'')!==''){
            $sid=$message['contentSid'];$catalogue=json_decode($c['whatsapp_templates_json']??'[]',true,32,JSON_THROW_ON_ERROR);
            if(!is_string($sid)||!isset($catalogue[$sid]))throw new Forbidden('Choose a registered approved WhatsApp template.');
            $template=$this->templates->inspect($c['account_sid'],$secret,$sid);
            $rendered=$this->render($template,$message['variables']??[]);
            if($template['category']==='MARKETING'&&$check['purpose']['purpose_key']!=='marketing_communications')throw new Forbidden('Marketing templates require marketing consent.');
            if($template['category']==='UTILITY'&&$check['purpose']['purpose_key']!=='customer_service')throw new Forbidden('Utility templates require a customer-service purpose.');
            $proof['message']=['recipient'=>$peer,'body'=>$rendered,'contentSid'=>$sid,'variables'=>$message['variables']??[],
                'templateHash'=>hash('sha256',json_encode($template,JSON_THROW_ON_ERROR))];
        }else{
            if($check['purpose']['purpose_key']!=='customer_service')throw new Forbidden('Free-text WhatsApp replies require a customer-service purpose.');
            $rows=$this->store->rows("SELECT provider_message_id FROM nexa_messaging_receipt WHERE tenant_id=? AND service_id=? AND connection_id=? AND conversation_id=? AND kind='inbound' ORDER BY received_at DESC,id DESC LIMIT 3",[...$this->store->scope(),$c['id'],$check['root']->getId()]);
            foreach($rows as $row){
                $session=$this->sessions->inspect($c['account_sid'],$secret,$c['sender'],$peer,$row['provider_message_id'],new DateTimeImmutable('now'));
                if($session['open']){$proof['inboundAt']=$session['inboundAt'];break;}
            }
            if(!isset($proof['inboundAt']))throw new Forbidden('The WhatsApp reply window is closed. Choose an approved template.');
            $proof['message']=['recipient'=>$peer,'body'=>$message['body']];
        }
        return $proof;
    }
    public function assertCurrent(array $proof,array $check,?array $message=null): array
    {
        $age=time()-$proof['checkedAt'];
        if($age<0||$age>30||$proof['scope']!==$this->store->scope()||$proof['connectionId']!==$check['connection']['id']||$proof['conversationId']!==$check['root']->getId()||$proof['purposeId']!==$check['purpose']['id']||$proof['consentEventId']!==$check['purpose']['source_event_id']||$proof['connectionVersion']!==(int)$check['connection']['version_number']||$proof['recipient']!==$check['recipient'])throw new Forbidden('WhatsApp provider proof changed. Try again.');
        if(isset($proof['inboundAt'])&&!WhatsAppMessagePolicy::sessionOpen(new DateTimeImmutable($proof['inboundAt']),new DateTimeImmutable('now')))throw new Forbidden('The WhatsApp reply window closed.');
        if($message!==null&&$proof['message']!==$message)throw new Forbidden('The approved WhatsApp message changed. Create a new message.');
        return $proof['message'];
    }
    private function render(array $template,array $variables): string
    {
        $keys=array_map('strval',array_keys($variables));$expected=array_map('strval',$template['variableKeys']);sort($keys);sort($expected);
        if($keys!==$expected)throw new BadRequest('Complete exactly the approved template variables.');
        $replace=[];foreach($variables as $key=>$value){
            if(!is_string($value)||trim($value)===''||mb_strlen($value)>500||!mb_check_encoding($value,'UTF-8')||preg_match('/[{}\x00-\x1f\x7f]/',$value))throw new BadRequest('Enter valid single-line template values.');
            $replace['{{'.$key.'}}']=$value;
        }
        $body=strtr($template['body'],$replace);
        if(mb_strlen($body)>1600)throw new BadRequest('The rendered template exceeds the message limit.');
        return $body;
    }
}
