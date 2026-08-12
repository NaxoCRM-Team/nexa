<?php

namespace Espo\Custom\Tools\Contact\Api;

use Espo\Core\Acl;
use Espo\Core\Acl\Table;
use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\ORM\EntityManager;

final class PostTitle implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    public function process(Request $request): Response
    {
        $id = trim((string) $request->getRouteParam('id'));
        $body = $request->getParsedBody();
        $title = trim((string) ($body->value ?? ''));

        if ($id === '' || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
            throw new BadRequest('Invalid contact ID.');
        }
        if (mb_strlen($title) > 100) {
            throw new BadRequest('Title must contain no more than 100 characters.');
        }

        // TenantQueryProcessor scopes this lookup and the following save to the
        // trusted request tenant and CRM service.
        $contact = $this->entityManager->getRDBRepository('Contact')->getById($id);
        if (!$contact) {
            throw new NotFound('Contact not found.');
        }
        if (!$this->acl->check($contact, Table::ACTION_EDIT) ||
            !$this->acl->checkField('Contact', 'title', Table::ACTION_EDIT)) {
            throw new Forbidden('Title is not editable for this contact.');
        }

        // Title is stored on Contact. The existing Accounts hook also mirrors
        // it to the primary AccountContact role when a primary Account exists.
        $contact->set('title', $title !== '' ? $title : null);
        $this->entityManager->saveEntity($contact);

        return ResponseComposer::json(['id' => $id, 'value' => $title !== '' ? $title : null])
            ->setHeader('Cache-Control', 'private, no-store');
    }
}
