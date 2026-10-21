<?php
declare(strict_types=1);
namespace Espo\Custom\Tools\Conversation;

use Espo\Core\Exceptions\BadRequest;

/** Approved template inspection is read-only and never follows provider-supplied links. */
class WhatsAppTemplateLookup extends TwilioMessagingLookup
{
    public function inspect(string $account,#[\SensitiveParameter] string $secret,string $sid): array
    {
        if(!preg_match('/^AC[0-9a-fA-F]{32}$/D',$account)||!preg_match('/^[0-9a-fA-F]{32}$/D',$secret)||!preg_match('/^HX[0-9a-fA-F]{32}$/D',$sid))throw new BadRequest('Enter a valid WhatsApp Content SID.');
        $path='/v1/Content/'.$sid;
        $content=$this->fetchAt('content.twilio.com',$account,$secret,$path);
        $approval=$this->fetchAt('content.twilio.com',$account,$secret,$path.'/ApprovalRequests');
        $body=$content['types']['twilio/text']['body']??'';
        if(!is_string($body))throw new BadRequest('Choose an approved text template.');
        preg_match_all('/\{\{([1-9][0-9]?)\}\}/',$body,$matches);$keys=array_values(array_unique($matches[1]));
        $validated=WhatsAppMessagePolicy::approvedText($content,$approval,$account,$sid,array_fill_keys($keys,'Value'));
        return ['contentSid'=>$sid,'name'=>(string)($approval['whatsapp']['name']??''),'language'=>$validated['language'],'category'=>$validated['category'],'body'=>$body,'variableKeys'=>$keys];
    }
}
