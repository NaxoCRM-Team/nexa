<?php

namespace Espo\Custom\Tools\Attachment\Api;

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

/**
 * Generic attachment byte-serving route, authenticated via the normal
 * Bearer-token API auth path rather than the stock ?entryPoint=download/
 * ?entryPoint=image cookie-based entry points - those intermittently reject
 * genuinely valid sessions (a pre-existing race condition, confirmed
 * separately from this route). Carries the same access check Download.php
 * itself uses (Acl::checkEntity), so this is not a security relaxation -
 * just a reliable auth path in front of the same check.
 */
final class GetFile implements Action
{
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

        if (!$attachment) {
            throw new NotFound('Attachment not found.');
        }

        if ($attachment->isBeingUploaded()) {
            throw new NotFound('Attachment not found.');
        }

        if (!$this->acl->checkEntity($attachment)) {
            throw new Forbidden('No access to attachment.');
        }

        return ResponseComposer::json([
            'id' => $id,
            'name' => $attachment->getName(),
            'mimeType' => $attachment->getType(),
            'data' => base64_encode($this->fileStorageManager->getContents($attachment)),
        ])->setHeader('Cache-Control', 'private, no-store');
    }
}
