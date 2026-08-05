<?php

namespace Espo\ORM;

interface TenantIdProvider
{
    public function getTenantId(): ?string;

    public function getServiceId(): ?string;
}
