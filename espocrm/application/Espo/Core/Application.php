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

                $this->container->getByClass(TenantContextStore::class)->runWith($maintenanceTenant, $run);

                return;
            }

            $resolver = $this->container->getByClass(TenantResolver::class);
            $authToken = is_string($_COOKIE['auth-token'] ?? null) ? $_COOKIE['auth-token'] : '';
            $tenant = $authToken !== '' ? $resolver->resolveAuthToken($authToken) : null;

            if ($tenant === null) {
                $host = preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? '') ?? '';
                $tenant = $resolver->resolveHost($host);
            }

            if ($tenant === null) {
                throw new \RuntimeException('The request host is not assigned to an active tenant.');
            }

            $this->container->getByClass(TenantContextStore::class)->runWith($tenant, $run);
        } catch (RunnerException $e) {
            die($e->getMessage());
        }
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
