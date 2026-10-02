<?php

declare(strict_types=1);

namespace Espo\Custom\Tools\Event;

use DateTimeImmutable;
use Espo\Core\Exceptions\BadRequest;
use stdClass;

/** Validates and normalizes the public M11 behavioral-event contract. */
final class EventContract
{
    private const MAX_PROPERTIES_BYTES = 65536;
    private const MAX_CONSENT_BYTES = 16384;

    /** @var array<string, array{category: string, consent: string}> */
    private const STANDARD_EVENTS = [
        'page.viewed' => ['category' => 'website', 'consent' => 'analytics'],
        'landing_page.viewed' => ['category' => 'website', 'consent' => 'analytics'],
        'link.clicked' => ['category' => 'website', 'consent' => 'analytics'],
        'form.submitted' => ['category' => 'form', 'consent' => 'necessary'],
        'asset.downloaded' => ['category' => 'content', 'consent' => 'analytics'],
        'video.started' => ['category' => 'content', 'consent' => 'analytics'],
        'video.progressed' => ['category' => 'content', 'consent' => 'analytics'],
        'video.completed' => ['category' => 'content', 'consent' => 'analytics'],
        'webinar.registered' => ['category' => 'event', 'consent' => 'necessary'],
        'webinar.attended' => ['category' => 'event', 'consent' => 'analytics'],
        'purchase.completed' => ['category' => 'commerce', 'consent' => 'necessary'],
        'email.replied' => ['category' => 'email', 'consent' => 'necessary'],
    ];

    /** @return array<string, mixed> */
    public function normalize(stdClass $input): array
    {
        $eventType = $this->requiredText($input->eventType ?? null, 128, 'Enter an event type.');
        $definition = self::STANDARD_EVENTS[$eventType] ?? null;

        if ($definition === null) {
            if (!preg_match('/^custom\.[a-z0-9][a-z0-9._-]{1,119}$/', $eventType)) {
                throw new BadRequest('Use a supported event type or a custom.* event name.');
            }

            $definition = [
                'category' => 'custom',
                'consent' => $this->consentCategory($input->consentCategory ?? 'analytics'),
            ];
        }

        $version = (int) ($input->eventVersion ?? 1);
        if ($version !== 1) {
            throw new BadRequest('This event contract version is not supported.');
        }

        $consent = $this->jsonObject($input->consent ?? new stdClass(), self::MAX_CONSENT_BYTES, 'consent');
        if ($definition['consent'] !== 'necessary' && ($consent[$definition['consent']] ?? null) !== 'granted') {
            throw new BadRequest(sprintf('%s consent is required for this event.', ucfirst($definition['consent'])));
        }

        $contactId = $this->optionalText($input->contactId ?? null, 36);
        $identity = $this->identityEvidence($input->identityEvidence ?? null, $contactId !== null);

        return [
            'eventType' => $eventType,
            'eventVersion' => $version,
            'eventCategory' => $definition['category'],
            'consentCategory' => $definition['consent'],
            'source' => $this->source($input->source ?? 'api'),
            'idempotencyKey' => $this->requiredText(
                $input->idempotencyKey ?? null,
                191,
                'Provide an idempotency key.'
            ),
            'correlationId' => $this->uuid($input->correlationId ?? null),
            'visitorKey' => $this->optionalText($input->visitorKey ?? null, 500),
            'sessionKey' => $this->optionalText($input->sessionKey ?? null, 500),
            'contactId' => $contactId,
            'accountId' => $this->optionalText($input->accountId ?? null, 36),
            'pageUrl' => $this->url($input->pageUrl ?? null, 'pageUrl'),
            'referrerUrl' => $this->url($input->referrerUrl ?? null, 'referrerUrl'),
            'properties' => $this->jsonObject(
                $input->properties ?? new stdClass(),
                self::MAX_PROPERTIES_BYTES,
                'properties'
            ),
            'consent' => $consent,
            'occurredAt' => $this->occurredAt($input->occurredAt ?? null),
            'summary' => $this->optionalText($input->summary ?? null, 255) ?? $eventType,
            'identityMethod' => $identity['method'],
            'identityEvidenceHash' => $identity['evidenceHash'],
        ];
    }

    /** @return array{method: ?string, evidenceHash: ?string} */
    private function identityEvidence(mixed $value, bool $required): array
    {
        if (!$required) {
            return ['method' => null, 'evidenceHash' => null];
        }

        if (!is_object($value) || ($value->verified ?? false) !== true) {
            throw new BadRequest('Verified identity evidence is required to link a visitor to a contact.');
        }

        $method = $this->requiredText($value->type ?? null, 40, 'Enter the identity verification method.');
        if (!in_array($method, ['authenticated_session', 'verified_email', 'form_submission', 'oauth_subject'], true)) {
            throw new BadRequest('The identity verification method is not supported.');
        }

        $reference = $this->requiredText(
            $value->reference ?? null,
            500,
            'Enter the identity verification reference.'
        );

        return [
            'method' => $method,
            'evidenceHash' => hash('sha256', $method . "\0" . $reference),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, int $maxBytes, string $field): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }

        if (!is_array($value)) {
            throw new BadRequest(sprintf('The %s value must be an object.', $field));
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > $maxBytes) {
            throw new BadRequest(sprintf('The %s value is too large.', $field));
        }

        return $value;
    }

    private function occurredAt(mixed $value): string
    {
        try {
            $date = $value ? new DateTimeImmutable((string) $value) : new DateTimeImmutable();
        } catch (\Throwable) {
            throw new BadRequest('Enter a valid event date and time.');
        }

        if ($date->getTimestamp() > (new DateTimeImmutable())->getTimestamp() + 300) {
            throw new BadRequest('The event date cannot be more than five minutes in the future.');
        }

        return $date->format('Y-m-d H:i:s.u');
    }

    private function url(mixed $value, string $field): ?string
    {
        $value = $this->optionalText($value, 2048);
        if ($value === null) {
            return null;
        }

        $parts = parse_url($value);
        if ($parts === false || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new BadRequest(sprintf('The %s value must be an HTTP or HTTPS URL.', $field));
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new BadRequest(sprintf('The %s value must not contain credentials.', $field));
        }

        return $value;
    }

    private function source(mixed $value): string
    {
        $source = $this->requiredText($value, 64, 'Enter an event source.');
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/', $source)) {
            throw new BadRequest('Use a lowercase event source containing letters, numbers, dots, dashes or underscores.');
        }

        return $source;
    }

    private function consentCategory(mixed $value): string
    {
        $category = $this->requiredText($value, 24, 'Enter a consent category.');
        if (!in_array($category, ['necessary', 'preference', 'analytics', 'advertising'], true)) {
            throw new BadRequest('The consent category is not supported.');
        }

        return $category;
    }

    private function uuid(mixed $value): ?string
    {
        $value = $this->optionalText($value, 36);
        if ($value !== null && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            throw new BadRequest('Enter a valid correlation identifier.');
        }

        return $value;
    }

    private function requiredText(mixed $value, int $maxLength, string $message): string
    {
        $value = $this->optionalText($value, $maxLength);
        if ($value === null) {
            throw new BadRequest($message);
        }

        return $value;
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }
}
