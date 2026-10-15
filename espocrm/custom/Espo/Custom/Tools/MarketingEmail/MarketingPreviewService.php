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
        private MarketingParameterTemplate $parameters,
        private \Espo\Custom\Tools\Customer\CustomerFoundationRecorder $recorder,
    ) {}

    /** Explain generated conditions using the same ACL-aware renderer as email preview. */
    public function decision(stdClass $input): array
    {
        $contactId = $input->contactId ?? null;
        if (!is_string($contactId) || $contactId === '' || strlen($contactId) > 64) throw new BadRequest('Choose a Contact for the decision.');
        $definition = clone $input;
        unset($definition->contactId);
        $body = $this->conditionalContent->compose($definition)['bodyHtml'];
        $conditions = $definition->conditions ?? [$definition];
        $mode = isset($definition->conditions) ? ($definition->matchMode ?? 'all') : 'all';
        $trace = [];
        foreach ($conditions as $condition) {
            $rule = clone $condition;
            unset($rule->matchingHtml, $rule->fallbackHtml);
            $rule->matchingText = 'NEXA_MATCH';
            $rule->fallbackText = 'NEXA_FALLBACK';
            $probe = $this->conditionalContent->compose($rule)['bodyHtml'];
            $rendered = $this->preview((object) ['contactId'=>$contactId, 'bodyHtml'=>$probe]);
            $matched = trim(strip_tags($rendered['bodyHtml'])) === 'NEXA_MATCH';
            $trace[] = ['field'=>$condition->field, 'operator'=>$condition->operator,
                'value'=>$condition->value ?? '', 'count'=>$condition->count ?? null,
                'pageUrl'=>$condition->pageUrl ?? null, 'matched'=>$matched];
        }
        $outcomes = array_column($trace, 'matched');
        $matched = $mode === 'all' ? !in_array(false, $outcomes, true) : in_array(true, $outcomes, true);
        // Tag the actual rendering so evidence cannot claim a branch different from its output.
        $tag = bin2hex(random_bytes(16));
        $annotatedBody = $this->conditionalContent->compose($definition, $tag)['bodyHtml'];
        $result = $this->preview((object) ['contactId'=>$contactId, 'bodyHtml'=>$annotatedBody]);
        $chosen = $matched ? 'matching' : 'fallback';
        if (!str_contains($result['bodyHtml'], $tag . $chosen)) throw new BadRequest('Contact evidence changed during the test. Please test again.');
        $result['bodyHtml'] = str_replace('<p>' . $tag . $chosen . '</p>', '', $result['bodyHtml']);
        $evidence = ['version'=>1, 'matchMode'=>$mode, 'conditions'=>$trace,
            'branch'=>$matched ? 'matching' : 'fallback', 'evaluatedAt'=>gmdate('Y-m-d\TH:i:s\Z'),
            'templateHash'=>hash('sha256', $body), 'outputHash'=>hash('sha256', $result['bodyHtml'])];
        $this->recorder->personalizationRecorded($contactId, $evidence);
        return ['bodyHtml'=>$result['bodyHtml'], 'evidence'=>$evidence];
    }

    /** @return array{subject:string,bodyHtml:string,preheader:string} */
    public function preview(stdClass $input): array
    {
        $context = $this->contexts->require();
        foreach (['MassEmail', 'EmailTemplate', 'Contact'] as $scope) {
            if (!$this->acl->checkScope($scope, 'read')) throw new Forbidden('Email preview is not allowed.');
        }
        $id = trim((string) ($input->contactId ?? ''));
        $body = (string) ($input->bodyHtml ?? '');
        $subject = (string) ($input->subject ?? '');
        $preheader = (string) ($input->preheader ?? '');
        if (property_exists($input, 'parameters') && !$input->parameters instanceof stdClass) throw new BadRequest('Preview parameters must be an object.');
        if ($id === '' || strlen($id) > 64) throw new BadRequest('Choose a Contact for the preview.');
        if (strlen($body) > 2_000_000 || mb_strlen($subject) > 255) throw new BadRequest('Preview content is too large.');
        $this->conditionalContent->validate($subject, $body);
        if (mb_strlen($preheader) > 255) throw new BadRequest('Preview preheader is too large.');
        $resolved = $this->parameters->resolve($subject, $body, $preheader, $input->parameters ?? new stdClass());
        $subject = $resolved['subject']; $body = $resolved['bodyHtml']; $preheader = $resolved['preheader'];
        $contact = $this->services->get('Contact')->getEntity($id);
        if (!$contact || $contact->get('tenantId') !== $context->tenantId || $contact->get('serviceId') !== $context->serviceId) {
            throw new NotFound('Contact not found.');
        }
        $template = $this->entityManager->getNewEntity(EmailTemplate::ENTITY_TYPE);
        $template->set(['subject' => $subject, 'body' => $body, 'isHtml' => true]);
        $result = $this->processor->process($template, Params::create()->withApplyAcl(true)->withCopyAttachments(false), Data::create()->withParent($contact));
        $preheaderTemplate = $this->entityManager->getNewEntity(EmailTemplate::ENTITY_TYPE);
        $preheaderTemplate->set(['subject' => $preheader, 'body' => '', 'isHtml' => true]);
        $renderedPreheader = $this->processor->process($preheaderTemplate, Params::create()->withApplyAcl(true)->withCopyAttachments(false), Data::create()->withParent($contact));
        return ['subject' => $result->getSubject(), 'bodyHtml' => $result->getBody(), 'preheader' => $renderedPreheader->getSubject()];
    }
}
