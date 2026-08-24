<?php

namespace Espo\Custom\Tools\Contact;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Entities\Email;

/** Enforces active Contact channel restrictions at outbound service boundaries. */
final class CommunicationRestrictionGuard
{
    private const CHANNELS = ['email', 'phone', 'sms', 'whatsapp', 'linkedin', 'postal', 'live_chat'];

    public function __construct(
        private EntityManager $entityManager,
        private TenantContextStore $tenantContextStore,
    ) {}

    public function assertContactAllowed(string $contactId, string $channel): void
    {
        $channel = $this->normalizeChannel($channel);
        $contact = $this->entityManager->getRDBRepository('Contact')->getById($contactId);

        if (!$contact || !$contact->get('doNotContact')) {
            return;
        }

        $channels = $this->parseChannels((string) $contact->get('doNotContactChannels'));
        if ($channels === [] || in_array('all', $channels, true) || in_array($channel, $channels, true)) {
            throw new Forbidden($this->message($channel));
        }
    }

    /** @param string[] $contactIds */
    public function assertContactsAllowed(array $contactIds, string $channel): void
    {
        foreach (array_values(array_unique(array_filter(array_map('strval', $contactIds)))) as $contactId) {
            $this->assertContactAllowed($contactId, $channel);
        }
    }

    public function assertEmailRecipientsAllowed(Email $email): void
    {
        $contactIds = $email->get('contactsIds');
        if (is_array($contactIds) && $contactIds !== []) {
            $this->assertContactsAllowed($contactIds, 'email');
        }

        foreach ($email->getToAddressList() as $address) {
            $contactId = $this->findRestrictedContactIdByEmail($address, 'email');
            if ($contactId !== null) {
                throw new Forbidden($this->message('email'));
            }
        }
    }

    private function findRestrictedContactIdByEmail(string $address, string $channel): ?string
    {
        $address = strtolower(trim($address));
        if ($address === '') {
            return null;
        }

        $tenant = $this->tenantContextStore->require();
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT c.id, c.do_not_contact_channels ' .
            'FROM contact c ' .
            'INNER JOIN entity_email_address eea ON eea.entity_id = c.id AND eea.entity_type = \'Contact\' AND eea.deleted = 0 ' .
            'INNER JOIN email_address ea ON ea.id = eea.email_address_id AND ea.deleted = 0 ' .
            'WHERE c.deleted = 0 AND c.do_not_contact = 1 AND c.tenant_id = :tenantId ' .
            'AND c.service_id = :serviceId AND ea.lower = :address'
        );
        $statement->execute([
            'tenantId' => $tenant->tenantId,
            'serviceId' => $tenant->serviceId,
            'address' => $address,
        ]);

        while ($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
            $channels = $this->parseChannels((string) ($row['do_not_contact_channels'] ?? ''));
            if ($channels === [] || in_array('all', $channels, true) || in_array($channel, $channels, true)) {
                return (string) $row['id'];
            }
        }

        return null;
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new \InvalidArgumentException('Unknown communication channel.');
        }

        return $channel;
    }

    /** @return string[] */
    private function parseChannels(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $channel): string => strtolower(trim($channel)),
            explode(',', $value),
        ))));
    }

    private function message(string $channel): string
    {
        $label = $channel === 'phone' ? 'Phone outreach' : ucfirst($channel);

        return $label . ' is restricted for this contact. A tenant admin must remove the restriction first.';
    }
}
