<?php

namespace Espo\Core\Tenant\Api;

use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Core\Tenant\TenantResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TenantResolver $tenantResolver,
        private TenantContextStore $tenantContextStore,
        private PlatformExecutionGateway $platformExecutionGateway,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Authentication begins before a tenant context exists. Only the exact
        // allow-listed public routes may enter platform context; every other
        // request must resolve an existing tenant below.
        if ($this->isPublicPlatformRoute($request)) {
            return $this->platformExecutionGateway->run(
                'public authentication and tenant registration',
                fn () => $handler->handle($request)
            );
        }

        $resetRequestId = $this->extractPasswordResetRequestId($request);
        $identifier = $this->extractLoginIdentifier($request);

        if ($resetRequestId !== null) {
            $tenant = $this->tenantResolver->resolvePasswordChangeRequest($resetRequestId);

            if ($tenant === null) {
                return $this->errorResponse(401, 'Unauthorized');
            }
        } elseif ($identifier !== null) {
            $tenant = $this->tenantResolver->resolveLoginIdentifier($identifier);

            if ($tenant === null) {
                return $this->errorResponse(401, 'Unauthorized');
            }
        } else {
            $tenant = $this->tenantResolver->resolveHost($request->getUri()->getHost());

            if ($tenant === null) {
                return $this->errorResponse(403, 'Tenant unavailable');
            }
        }

        return $this->tenantContextStore->runWith(
            $tenant,
            fn () => $handler->handle($request->withAttribute('nexaTenant', $tenant))
        );
    }

    private function errorResponse(int $status, string $message): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode(['message' => $message], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function isPublicPlatformRoute(ServerRequestInterface $request): bool
    {
        $method = strtoupper($request->getMethod());
        // Matched by suffix, not exact path: this app supports being installed
        // under any subfolder (see PortableSubfolderTest), and this URI's path
        // isn't guaranteed to already be stripped of that prefix at this point.
        $path = (string) $request->getUri()->getPath();

        $postRouteSuffixes = [
            '/api/v1/Nexa/signup',
            '/api/v1/Nexa/signup/profile',
            '/api/v1/Nexa/signup/verify',
            '/api/v1/Nexa/signup/resend',
            '/api/v1/Nexa/auth/recovery',
            // Twilio-originated webhooks: no EspoCRM session exists, so no tenant
            // can be resolved from login identity/host here. Authenticity is
            // established by X-Twilio-Signature (see Tools/Call/TwilioClient),
            // and the handlers resolve their own tenant from the stored
            // nexa_call_session row via correlation_id - never from this context.
            '/api/v1/Nexa/call/twiml',
            '/api/v1/Nexa/call/status',
        ];

        if ($method === 'POST') {
            foreach ($postRouteSuffixes as $suffix) {
                if (str_ends_with($path, $suffix)) {
                    return true;
                }
            }
        }

        return $method === 'GET' && (
            str_ends_with($path, '/api/v1/Nexa/auth/providers') ||
            preg_match('#/api/v1/Nexa/auth/provider/[a-z0-9_-]+/(start|callback)$#', $path) === 1
        );
    }

    private function extractLoginIdentifier(ServerRequestInterface $request): ?string
    {
        $value = trim($request->getHeaderLine('Espo-Authorization'));

        if ($value === '') {
            $value = trim($request->getHeaderLine('Authorization'));

            if (str_starts_with(strtolower($value), 'basic ')) {
                $value = trim(substr($value, 6));
            }
        }

        if ($value === '') {
            return null;
        }

        $decoded = base64_decode($value, true);

        if (!is_string($decoded)) {
            return null;
        }

        $separator = strpos($decoded, ':');

        if ($separator === false) {
            return null;
        }

        $identifier = trim(substr($decoded, 0, $separator));

        return $identifier !== '' ? $identifier : null;
    }

    private function extractPasswordResetRequestId(ServerRequestInterface $request): ?string
    {
        $path = '/' . trim($request->getUri()->getPath(), '/');
        if (strtoupper($request->getMethod()) !== 'POST' ||
            $path !== '/api/v1/User/changePasswordByRequest') {
            return null;
        }

        $body = $request->getParsedBody();
        $requestId = is_array($body) ? ($body['requestId'] ?? null) :
            (is_object($body) ? ($body->requestId ?? null) : null);

        return is_string($requestId) && trim($requestId) !== '' ? trim($requestId) : null;
    }
}
