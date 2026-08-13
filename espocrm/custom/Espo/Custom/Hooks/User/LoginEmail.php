<?php

namespace Espo\Custom\Hooks\User;

use Espo\Core\Exceptions\Conflict;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Keeps the public login identity normalized and globally unique.
 *
 * User names remain an internal EspoCRM identity. Interactive authentication
 * uses this field so two tenants can never claim the same email address.
 *
 * @implements BeforeSave<User>
 */
final class LoginEmail implements BeforeSave
{
    public static int $order = 2;

    public function __construct(private EntityManager $entityManager)
    {}

    /** @param User $entity */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (in_array($entity->getType(), [User::TYPE_API, User::TYPE_SYSTEM], true)) {
            $entity->set('loginEmail', null);

            return;
        }

        $email = $this->incomingPrimaryEmail($entity);

        // Existing accounts without an email retain username access until an
        // administrator assigns a primary email. New interactive users must
        // have one because email is their public sign-in identity.
        if ($email === null) {
            if ($entity->isNew()) {
                throw new Conflict('An email address is required for this user.');
            }

            return;
        }

        $normalized = strtolower($email);
        $statement = $this->entityManager->getPDO()->prepare(
            'SELECT id FROM `user` WHERE login_email = :email AND id <> :id LIMIT 1'
        );
        $statement->execute([
            'email' => $normalized,
            'id' => $entity->getId() ?? '',
        ]);

        if ($statement->fetchColumn() !== false) {
            throw new Conflict('This email address is already connected to a Nexa account.');
        }

        $entity->set('loginEmail', $normalized);
    }

    private function incomingPrimaryEmail(User $entity): ?string
    {
        $value = trim((string) $entity->get('emailAddress'));

        if ($value === '' && $entity->has('emailAddressData')) {
            $items = $entity->get('emailAddressData');

            if (is_array($items)) {
                foreach ($items as $item) {
                    $item = is_object($item) ? get_object_vars($item) : $item;

                    if (!is_array($item) || empty($item['primary'])) {
                        continue;
                    }

                    $value = trim((string) ($item['emailAddress'] ?? $item['name'] ?? ''));
                    break;
                }
            }
        }

        if ($value === '' && !$entity->isNew()) {
            $value = trim((string) $entity->get('loginEmail'));
        }

        if ($value === '') {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new Conflict('Enter a valid email address.');
        }

        return $value;
    }
}
