<?php

namespace Espo\Custom\Tools\Contact\Api;

use Espo\Core\Acl;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\ORM\EntityManager;
use Espo\Entities\Attachment;

final class GetProfileImage implements Action
{
    private const ALLOWED_TYPE_LIST = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private FileStorageManager $fileStorageManager,
        private Acl $acl,
    ) {}

    public function process(Request $request): Response
    {
        $id = trim((string) $request->getRouteParam('id'));

        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw new BadRequest('Invalid attachment ID.');
        }

        $attachment = $this->entityManager
            ->getRDBRepositoryByClass(Attachment::class)
            ->getById($id);

        if (!$attachment ||
            $attachment->getRelatedType() !== 'Contact' ||
            $attachment->getTargetField() !== 'profileImage' ||
            $attachment->getRole() !== Attachment::ROLE_ATTACHMENT) {
            throw new NotFound('Profile image not found.');
        }

        $contactId = (string) $attachment->get('relatedId');
        $contact = $contactId !== ''
            ? $this->entityManager->getRDBRepository('Contact')->getById($contactId)
            : null;

        if (!$contact || $contact->get('profileImageId') !== $id) {
            throw new NotFound('Profile image not found.');
        }

        if (!$this->acl->checkEntityRead($contact)) {
            throw new Forbidden('Profile image is not accessible.');
        }

        $type = (string) $attachment->getType();

        if (!in_array($type, self::ALLOWED_TYPE_LIST, true)) {
            throw new Forbidden('Unsupported profile image type.');
        }

        return ResponseComposer::json([
            'id' => $id,
            'mimeType' => $type,
            'data' => base64_encode($this->fileStorageManager->getContents($attachment)),
        ])->setHeader('Cache-Control', 'private, no-store');
    }
}
