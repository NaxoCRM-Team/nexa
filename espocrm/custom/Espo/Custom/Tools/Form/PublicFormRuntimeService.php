<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Form;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Custom\Tools\Consent\ConsentService;
use Espo\Entities\LeadCapture;
use PDO;
use stdClass;

/** Adds governed consent and attribution around the native public form runtime. */
final class PublicFormRuntimeService
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
        private Metadata $metadata,
        private Config $config,
        private ConsentService $consentService,
    ) {}

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function enhance(LeadCapture $form, array $data, Request $request): array
    {
        $context = $this->tenantContextStore->require();
        $configuration = $this->publishedConfiguration($context, $form->getId());

        if ($configuration === null) return $data;

        $purpose = $this->purpose($context, (string) ($configuration['consentPurposeId'] ?? ''));
        $submissionKey = $this->uuid();
        $referrer = $this->url($request->getHeader('Referer'));
        $sourcePage = $this->url($request->getQueryParam('sourcePage')) ?? $referrer;
        $policyVersion = $purpose['policy_version'] ?? null;
        $channel = $configuration['consentChannel'] ?? null;

        if ($purpose !== null && is_string($channel) && $channel !== '') {
            $label = trim((string) ($configuration['consentLabel'] ?? ''));
            $label = $label !== '' ? $label : sprintf('I agree to %s via %s.', $purpose['name'], str_replace('_', ' ', $channel));
            if (!($data['fieldDefs'] ?? null) instanceof stdClass) {
                $data['fieldDefs'] = (object) ($data['fieldDefs'] ?? []);
            }
            $data['fieldDefs']->nexaConsentAccepted = [
                'type' => 'bool',
                'required' => true,
            ];
            if (!($data['metadata']['fields'] ?? null) instanceof stdClass) {
                $data['metadata']['fields'] = (object) ($data['metadata']['fields'] ?? []);
            }
            $data['metadata']['fields']->bool = $this->metadata->get('fields.bool');
            $data['detailLayout'][0]['rows'][] = [['name' => 'nexaConsentAccepted']];
            if (($data['language']['Lead']['fields'] ?? null) instanceof stdClass) {
                $data['language']['Lead']['fields']->nexaConsentAccepted = $label;
            } else {
                $data['language']['Lead']['fields']['nexaConsentAccepted'] = $label;
            }
        }

        $data['nexaSubmissionDefaults'] = [
            'nexaSubmissionKey' => $submissionKey,
            'nexaFormVersion' => (int) ($configuration['_version'] ?? 0),
            'nexaSourcePage' => $sourcePage,
            'nexaReferrer' => $referrer,
            'nexaConsentPurposeId' => $purpose['id'] ?? null,
            'nexaConsentChannel' => $channel,
            'nexaConsentPolicyVersion' => $policyVersion,
        ];
        $data['nexaRedirectDelaySeconds'] = max(1, min(30, (int) ($configuration['redirectDelaySeconds'] ?? 4)));

        $this->insertEvent($context, $form->getId(), $submissionKey, 'view', $data['nexaSubmissionDefaults'], null, null, $request->getHeader('User-Agent'));

        return $data;
    }

    public function validateSubmission(LeadCapture $form, stdClass $data): void
    {
        $context = $this->tenantContextStore->require();
        $configuration = $this->publishedConfiguration($context, $form->getId());

        if ($configuration === null || empty($configuration['consentPurposeId'])) return;

        if (($data->nexaConsentAccepted ?? null) !== true) {
            throw new BadRequest('Consent is required before this form can be submitted.');
        }
    }

    /** @param array<string, mixed> $hookData */
    public function recordSubmission(LeadCapture $form, array $hookData): void
    {
        $data = $hookData['data'] ?? null;
        $targetId = is_string($hookData['targetId'] ?? null) ? $hookData['targetId'] : null;
        $targetType = is_string($hookData['targetType'] ?? null) ? $hookData['targetType'] : null;

        if (!$data instanceof stdClass || $targetId === null || $targetType === null) return;

        $context = $this->tenantContextStore->require();
        $submissionKey = $this->submissionKey($data->nexaSubmissionKey ?? null);
        $defaults = [
            'nexaVisitorId' => $this->text($data->nexaVisitorId ?? null, 64),
            'nexaSourcePage' => $this->url($data->nexaSourcePage ?? null),
            'nexaReferrer' => $this->url($data->nexaReferrer ?? null),
            'nexaConsentPurposeId' => $this->text($data->nexaConsentPurposeId ?? null, 36),
            'nexaConsentChannel' => $this->text($data->nexaConsentChannel ?? null, 24),
            'nexaConsentPolicyVersion' => $this->text($data->nexaConsentPolicyVersion ?? null, 40),
            'nexaConsentAccepted' => ($data->nexaConsentAccepted ?? null) === true,
            'nexaFormVersion' => max(0, (int) ($data->nexaFormVersion ?? 0)),
        ];
        $this->insertEvent($context, $form->getId(), $submissionKey, 'submission', $defaults, $targetType, $targetId, null);

        if ($targetType !== 'Contact' || !$defaults['nexaConsentAccepted'] || !$defaults['nexaConsentPurposeId'] || !$defaults['nexaConsentChannel']) return;

        $this->consentService->recordDecision((object) [
            'contactId' => $targetId,
            'purposeId' => $defaults['nexaConsentPurposeId'],
            'channel' => $defaults['nexaConsentChannel'],
            'status' => 'granted',
            'source' => 'form',
            'evidenceNote' => 'Consent submitted through published form ' . $form->getId() . '.',
            'evidence' => [
                'formId' => $form->getId(),
                'formVersion' => $defaults['nexaFormVersion'],
                'submissionKey' => $submissionKey,
                'sourcePage' => $defaults['nexaSourcePage'],
                'referrer' => $defaults['nexaReferrer'],
            ],
        ]);
    }

    /** @return array<string, mixed>|null */
    private function publishedConfiguration(TenantContext $context, string $formId): ?array
    {
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT v.configuration_json,v.version_number FROM nexa_form_version v INNER JOIN nexa_form_profile p ' .
            'ON p.lead_capture_id=v.lead_capture_id AND p.tenant_id=v.tenant_id AND p.service_id=v.service_id ' .
            "WHERE v.tenant_id=? AND v.service_id=? AND v.lead_capture_id=? AND p.status='published' " .
            'ORDER BY v.version_number DESC LIMIT 1'
        );
        $statement->execute([$context->tenantId, $context->serviceId, $formId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (!$row) return null;

        $configuration = json_decode((string) $row['configuration_json'], true) ?: [];
        $configuration['_version'] = (int) $row['version_number'];
        return $configuration;
    }

    /** @return array<string, mixed>|null */
    private function purpose(TenantContext $context, string $id): ?array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) return null;
        $statement = $this->entityManager->getPDO()->prepare('SELECT id,name,policy_version FROM nexa_consent_purpose WHERE id=? AND tenant_id=? AND service_id=? AND is_active=1 LIMIT 1');
        $statement->execute([$id, $context->tenantId, $context->serviceId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** @param array<string, mixed> $data */
    private function insertEvent(TenantContext $context, string $formId, string $submissionKey, string $type, array $data, ?string $targetType, ?string $targetId, ?string $userAgent): void
    {
        $secret = (string) $this->config->get('hashSecretKey', $context->tenantId);
        $statement = $this->entityManager->getPDO()->prepare(
            'INSERT IGNORE INTO nexa_form_event (id,tenant_id,service_id,lead_capture_id,submission_key,event_type,target_type,target_id,visitor_id,source_page,referrer,user_agent_hash,consent_purpose_id,consent_channel,consent_status,policy_version,form_version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $this->uuid(), $context->tenantId, $context->serviceId, $formId, $submissionKey, $type, $targetType, $targetId,
            $this->text($data['nexaVisitorId'] ?? null, 64), $this->url($data['nexaSourcePage'] ?? null), $this->url($data['nexaReferrer'] ?? null),
            $userAgent ? hash_hmac('sha256', $userAgent, $secret) : null,
            $this->text($data['nexaConsentPurposeId'] ?? null, 36), $this->text($data['nexaConsentChannel'] ?? null, 24),
            !empty($data['nexaConsentAccepted']) ? 'granted' : null, $this->text($data['nexaConsentPolicyVersion'] ?? null, 40),
            max(0, (int) ($data['nexaFormVersion'] ?? 0)),
        ]);
    }

    private function submissionKey(mixed $value): string
    {
        $value = trim((string) $value);
        return preg_match('/^[a-f0-9-]{36}$/i', $value) ? $value : $this->uuid();
    }

    private function url(mixed $value): ?string
    {
        $value = $this->text($value, 1000);
        return $value !== null && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    private function text(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
