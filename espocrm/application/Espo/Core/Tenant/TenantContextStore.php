<?php

namespace Espo\Core\Tenant;

use Espo\Core\Tenant\Exception\MissingTenantContext;
use Espo\ORM\TenantIdProvider;

final class TenantContextStore implements TenantIdProvider
{
    /** @var array<int, TenantContext|false> */
    private array $stack = [];

    public function current(): ?TenantContext
    {
        $frame = $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];

        return $frame instanceof TenantContext ? $frame : null;
    }

    public function getTenantId(): ?string
    {
        return $this->current()?->tenantId;
    }

    public function getServiceId(): ?string
    {
        return $this->current()?->serviceId;
    }

    public function require(): TenantContext
    {
        return $this->current() ?? throw new MissingTenantContext('A trusted tenant context is required.');
    }

    public function isPlatform(): bool
    {
        return $this->stack !== [] && $this->stack[array_key_last($this->stack)] === false;
    }

    public function runWith(TenantContext $context, callable $callback): mixed
    {
        $this->stack[] = $context;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }

    public function runAsPlatform(callable $callback): mixed
    {
        $this->stack[] = false;

        try {
            return $callback();
        } finally {
            array_pop($this->stack);
        }
    }
}
