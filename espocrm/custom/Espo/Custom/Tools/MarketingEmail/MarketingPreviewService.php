<?php
declare(strict_types=1);

namespace Espo\Custom\Tools\MarketingEmail;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\EmailTemplate;
use Espo\Tools\EmailTemplate\Data;
use Espo\Tools\EmailTemplate\Params;
use Espo\Tools\EmailTemplate\Processor;
use stdClass;

final class MarketingPreviewService
{
    public function __construct(
        private EntityManager $entityManager,
        private ServiceContainer $services,
        private TenantContextStore $contexts,
        private Acl $acl,
        private Processor $processor,
        private MarketingConditionalContentService $conditionalContent,
    ) {}

    /** @return array{subject:string,bodyHtml:string} */
    public function preview(stdClass $input): array
    {
        $context = $this->contexts->require();
        foreach (['MassEmail', 'EmailTemplate', 'Contact'] as $scope) {
            if (!$this->acl->checkScope($scope, 'read')) throw new Forbidden('Email preview is not allowed.');
        }
        $id = trim((string) ($input->contactId ?? ''));
        $body = (string) ($input->bodyHtml ?? '');
        $subject = (string) ($input->subject ?? '');
        if ($id === '' || strlen($id) > 64) throw new BadRequest('Choose a Contact for the preview.');
        if (strlen($body) > 2_000_000 || mb_strlen($subject) > 255) throw new BadRequest('Preview content is too large.');
        $this->conditionalContent->validate($subject, $body);
        $contact = $this->services->get('Contact')->getEntity($id);
        if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId) {
            throw new NotFound('Contact not found.');
        }
        $template = $this->entityManager->getNewEntity(EmailTemplate::ENTITY_TYPE);
        $template->set(['subject' => $subject, 'body' => $body, 'isHtml' => true]);
        $result = $this->processor->process($template, Params::create()->withApplyAcl(true)->withCopyAttachments(false), Data::create()->withParent($contact));
        return ['subject' => $result->getSubject(), 'bodyHtml' => $result->getBody()];
    }
}
