<?php

namespace Espo\Custom\Tools\Signup\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Custom\Tools\Signup\SignupService;
use Throwable;

/** Returns verified display defaults for an opaque social-signup attempt. */
final class PostProfile implements Action
{
    public function __construct(
        private SignupService $service,
        private SignupApiSupport $support,
    ) {}

    public function process(Request $request): Response
    {
        try {
            $this->support->assertJsonRequest($request);

            return $this->support->success(
                $this->service->socialProfile(
                    trim((string) ($request->getParsedBody()->attemptToken ?? '')),
                ),
            );
        } catch (Throwable $e) {
            return $this->support->problem($e);
        }
    }
}
