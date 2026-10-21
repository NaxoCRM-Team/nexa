<?php
declare(strict_types=1);
namespace Espo\Custom\Classes\Acl\Attachment;
use Espo\Core\Acl\{DefaultAccessChecker,ScopeData};
use Espo\Core\AclManager;
use Espo\Core\ORM\EntityManager;
use Espo\Entities\User;
use Espo\ORM\Entity;

/** Private message files cannot fall back to broad native attachment access. */
class AccessChecker extends \Espo\Classes\Acl\Attachment\AccessChecker
{
    public function __construct(DefaultAccessChecker $defaultAccessChecker,private AclManager $messagingAcl,private EntityManager $messagingEntities)
    {parent::__construct($defaultAccessChecker,$messagingAcl,$messagingEntities);}
    public function checkEntityRead(User $user,Entity $entity,ScopeData $data): bool
    {
        $type=$entity->get('parentType');
        if($type!=='NexaChatConversation'&&($type!=='NexaMessagingConversation'||$entity->get('field')!=='messages'))return parent::checkEntityRead($user,$entity,$data);
        if([$entity->get('tenantId'),$entity->get('serviceId')]!==[$user->get('tenantId'),$user->get('serviceId')])return false;
        $root=$this->messagingEntities->getEntityById($type,$entity->get('parentId'));
        return $root&&[$root->get('tenantId'),$root->get('serviceId')]===[$user->get('tenantId'),$user->get('serviceId')]&&$this->messagingAcl->checkEntity($user,$root,'read')&&$this->messagingAcl->checkField($user,$type,'messages','read');
    }
}
