<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2025 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core;

use Espo\Core\Application\Runner;
use Espo\Core\Application\RunnerParameterized;
use Espo\Core\Container\ContainerBuilder;
use Espo\Core\Application\RunnerRunner;
use Espo\Core\Application\Runner\Params as RunnerParams;
use Espo\Core\Application\Exceptions\RunnerException;
use Espo\Core\Tenant\PlatformExecutionGateway;
use Espo\Core\Tenant\TenantContext;
use Espo\Core\Tenant\TenantCurrencyConfigOverlay;
use Espo\Core\Tenant\TenantContextStore;
use Espo\Core\Tenant\TenantResolver;
use Espo\Core\Utils\Autoload;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Core\Utils\ClientManager;

/**
 * A central access point of the application.
 */
class Application
{
    protected Container $container;

    public function __construct()
    {
        date_default_timezone_set('UTC');

        $this->initContainer();
        $this->initAutoloads();
        $this->initPreloads();
    }

    protected function initContainer(): void
    {
        /** @var Container $container */
        $container = (new ContainerBuilder())->build();

        $this->container = $container;
    }

    /**
     * Run an application runner.
     *
     * @param class-string<Runner|RunnerParameterized> $className A runner class name.
     * @param ?RunnerParams $params Runner parameters.
     */
    public function run(string $className, ?RunnerParams $params = null): void
    {
        $runnerRunner = $this->getInjectableFactory()->create(RunnerRunner::class);

        try {
            $run = fn () => $runnerRunner->run($className, $params);

            // The base schema and tenant registry do not exist during the
            // browser installer. Tenant enforcement starts as soon as the
            // installer marks the application as installed.
            if (!$this->isInstalled()) {
                $run();

                return;
            }

            if (PHP_SAPI === 'cli') {
                if (str_ends_with($className, '\\Cron') || str_ends_with($className, '\\Job')) {
                    $this->container->getByClass(PlatformExecutionGateway::class)
                        ->run('CLI runner ' . $className, $run);

                    return;
                }

                $maintenanceTenant = $this->container->getByClass(TenantResolver::class)
                    ->resolveHost('localhost');

                if ($maintenanceTenant === null) {
                    throw new \RuntimeException('The local maintenance tenant is not configured.');
                }

                $this->runForTenant($maintenanceTenant, $run);

                return;
            }

            $resolver = $this->container->getByClass(TenantResolver::class);
            $authToken = is_string($_COOKIE['auth-token'] ?? null) ? $_COOKIE['auth-token'] : '';
            $tenant = $this->resolvePublicResourceTenant($resolver, $className);

            if ($tenant === null && $authToken !== '') {
                $tenant = $resolver->resolveAuthToken($authToken);
            }

            if ($tenant === null) {
                $tenant = $this->resolveLoginRequestTenant($resolver, $className);
            }

            if ($tenant === null) {
                $host = preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '';
                $tenant = $resolver->resolveHost($host);
            }

            if ($tenant === null) {
                $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

                if ($this->isSharedLoginApiRequest($className, $requestPath)) {
                    $this->container->getByClass(PlatformExecutionGateway::class)
                        ->run('shared login API', $run);

                    return;
                }

                if ($this->isPublicPlatformApiRequest($className, $requestPath)) {
                    $this->container->getByClass(PlatformExecutionGateway::class)
                        ->run('public authentication and callback API', $run);

                    return;
                }

                if (
                    str_ends_with($className, '\\ApplicationRunners\\Client') &&
                    str_ends_with(rtrim($requestPath, '/'), '/login')
                ) {
                    $this->container->getByClass(PlatformExecutionGateway::class)
                        ->run('shared login client shell', $run);

                    return;
                }

                throw new \RuntimeException('The request host is not assigned to an active tenant.');
            }

            $this->runForTenant($tenant, $run);
        } catch (RunnerException $e) {
            die($e->getMessage());
        }
    }

    private function resolvePublicResourceTenant(TenantResolver $resolver, string $className): ?TenantContext
    {
        if (
            str_ends_with($className, '\\ApplicationRunners\\EntryPoint') &&
            strcasecmp((string) ($_GET['entryPoint'] ?? ''), 'LeadCaptureForm') === 0
        ) {
            return $resolver->resolveLeadCaptureFormId((string) ($_GET['id'] ?? ''));
        }

        if (
            str_ends_with($className, '\\ApplicationRunners\\Api') &&
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
        ) {
            $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

            if (preg_match('#/api/v1/LeadCapture/form/([A-Za-z0-9]{17})/?$#', $path, $matches) === 1) {
                return $resolver->resolveLeadCaptureFormId($matches[1]);
            }
        }

        return null;
    }

    private function resolveLoginRequestTenant(TenantResolver $resolver, string $className): ?TenantContext
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        if (
            !str_ends_with($className, '\\ApplicationRunners\\Api') ||
            !str_ends_with($path, '/api/v1/App/user')
        ) {
            return null;
        }

        $authorization = trim((string) ($_SERVER['HTTP_ESPO_AUTHORIZATION'] ?? ''));

        if ($authorization === '') {
            $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));

            if (str_starts_with(strtolower($authorization), 'basic ')) {
                $authorization = trim(substr($authorization, 6));
            }
        }

        $decoded = base64_decode($authorization, true);

        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            return null;
        }

        return $resolver->resolveLoginIdentifier(explode(':', $decoded, 2)[0]);
    }

    private function isSharedLoginApiRequest(string $className, string $path): bool
    {
        return str_ends_with($className, '\\ApplicationRunners\\Api') &&
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET' &&
            str_ends_with($path, '/api/v1/App/user');
    }

    private function isPublicPlatformApiRequest(string $className, string $path): bool
    {
        if (!str_ends_with($className, '\\ApplicationRunners\\Api')) {
            return false;
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $postRouteSuffixes = [
            '/api/v1/Nexa/signup',
            '/api/v1/Nexa/signup/profile',
            '/api/v1/Nexa/signup/verify',
            '/api/v1/Nexa/signup/resend',
            '/api/v1/Nexa/auth/recovery',
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
            str_ends_with($path, '/api/v1/I18n') ||
            str_ends_with($path, '/api/v1/Settings') ||
            str_ends_with($path, '/api/v1/Nexa/auth/providers') ||
            preg_match('#/api/v1/Nexa/auth/provider/[a-z0-9_-]+/(start|callback)$#', $path) === 1 ||
            preg_match('#/api/v1/Nexa/mail/oauth/[a-z0-9_-]+/callback$#', $path) === 1
        );
    }

    private function runForTenant(TenantContext $tenant, callable $callback): mixed
    {
        return $this->container->getByClass(TenantContextStore::class)->runWith(
            $tenant,
            fn () => $this->getInjectableFactory()->create(TenantCurrencyConfigOverlay::class)
                ->run($tenant, $callback),
        );
    }

    /**
     * Whether the application is installed.
     */
    public function isInstalled(): bool
    {
        return $this->getConfig()->get('isInstalled');
    }

    /**
     * Get the service container.
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    protected function getInjectableFactory(): InjectableFactory
    {
        return $this->container->getByClass(InjectableFactory::class);
    }

    protected function getApplicationUser(): ApplicationUser
    {
        return $this->container->getByClass(ApplicationUser::class);
    }

    protected function getClientManager(): ClientManager
    {
        return $this->container->getByClass(ClientManager::class);
    }

    protected function getMetadata(): Metadata
    {
        return $this->container->getByClass(Metadata::class);
    }

    protected function getConfig(): Config
    {
        return $this->container->getByClass(Config::class);
    }

    protected function initAutoloads(): void
    {
        $autoload = $this->getInjectableFactory()->create(Autoload::class);

        $autoload->register();
    }

    /**
     * Initialize services that has the 'preload' parameter.
     */
    protected function initPreloads(): void
    {
        foreach ($this->getMetadata()->get(['app', 'containerServices']) ?? [] as $name => $defs) {
            if ($defs['preload'] ?? false) {
                $this->container->get($name);
            }
        }
    }

    /**
     * Set a base path of an index file related to the application directory. Used for a portal.
     */
    public function setClientBasePath(string $basePath): void
    {
        $this->getClientManager()->setBasePath($basePath);
    }

    /**
     * Set up the system user. The system user is used when no user is logged in.
     */
    public function setupSystemUser(): void
    {
        $this->getApplicationUser()->setupSystemUser();
    }
}
