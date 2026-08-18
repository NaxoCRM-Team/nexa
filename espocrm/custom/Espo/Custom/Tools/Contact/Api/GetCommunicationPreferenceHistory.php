<?php

namespace Espo\Custom\Tools\Contact\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Custom\Tools\Contact\ContactLifecycleService;

/** Returns the authenticated tenant's communication-preference audit for one Contact. */
final class GetCommunicationPreferenceHistory implements Action
{
    public function __construct(private ContactLifecycleService $service) {}

    public function process(Request $request): Response
    {
        return ResponseComposer::json($this->service->getCommunicationPreferenceHistory(
            trim((string) $request->getRouteParam('id'))
        ))->setHeader('Cache-Control', 'private, no-store');
    }
}
