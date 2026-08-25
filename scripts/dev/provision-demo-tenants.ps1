[CmdletBinding()]
param(
    [ValidateSet('Docker', 'Local')]
    [string] $Mode = 'Docker',
    [string] $PhpPath = 'php',
    [string] $EnvironmentFile = '.env',
    [string] $TenantAUserName,
    [string] $TenantBUserName
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$environmentNames = @(
    'NEXA_TENANT_A_ADMIN_USERNAME',
    'NEXA_TENANT_A_ADMIN_PASSWORD',
    'NEXA_TENANT_A_ADMIN_EMAIL',
    'NEXA_TENANT_B_ADMIN_USERNAME',
    'NEXA_TENANT_B_ADMIN_PASSWORD',
    'NEXA_TENANT_B_ADMIN_EMAIL'
)
$previousEnvironment = @{}
foreach ($name in $environmentNames) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}

function Read-EnvironmentFile([string] $Path) {
    $values = @{}

    if (-not (Test-Path -LiteralPath $Path)) { return $values }

    foreach ($line in Get-Content -LiteralPath $Path) {
        if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { continue }
        $values[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
    }

    return $values
}

function Read-PlainTextPassword([string] $Prompt) {
    $securePassword = Read-Host $Prompt -AsSecureString
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($securePassword)

    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
    }
}

try {
    $environmentPath = if ([IO.Path]::IsPathRooted($EnvironmentFile)) {
        $EnvironmentFile
    } else {
        Join-Path $root $EnvironmentFile
    }
    $environment = Read-EnvironmentFile $environmentPath
    $TenantAUserName = if ($TenantAUserName) { $TenantAUserName } else { $environment['DEMO_TENANT_A_ADMIN_USERNAME'] }
    $TenantBUserName = if ($TenantBUserName) { $TenantBUserName } else { $environment['DEMO_TENANT_B_ADMIN_USERNAME'] }
    $TenantAUserName = if ($TenantAUserName) { $TenantAUserName } else { 'demo-admin' }
    $TenantBUserName = if ($TenantBUserName) { $TenantBUserName } else { 'demo-admin-b' }

    if ($TenantAUserName -eq $TenantBUserName) {
        throw 'Tenant A and Tenant B demo administrator usernames must be different.'
    }

    if ($Mode -eq 'Docker') {
        if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
            throw 'Docker is unavailable.'
        }

        & docker compose --project-directory $root exec -T `
            -e "NEXA_TENANT_A_ADMIN_USERNAME=$TenantAUserName" `
            -e "NEXA_TENANT_B_ADMIN_USERNAME=$TenantBUserName" `
            espocrm php bin/provision-demo-tenants.php

        if ($LASTEXITCODE -ne 0) {
            throw 'Demo tenant administrator provisioning failed.'
        }

        return
    }

    if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) {
        throw "PHP executable not found: $PhpPath"
    }

    $tenantAPassword = $environment['DEMO_TENANT_A_ADMIN_PASSWORD']
    $tenantBPassword = $environment['DEMO_TENANT_B_ADMIN_PASSWORD']
    $tenantAPassword = if ($tenantAPassword) { $tenantAPassword } else { Read-PlainTextPassword 'Tenant A administrator password' }
    $tenantBPassword = if ($tenantBPassword) { $tenantBPassword } else { Read-PlainTextPassword 'Tenant B administrator password' }

    $env:NEXA_TENANT_A_ADMIN_USERNAME = $TenantAUserName
    $env:NEXA_TENANT_A_ADMIN_PASSWORD = $tenantAPassword
    $env:NEXA_TENANT_A_ADMIN_EMAIL = if ($environment['DEMO_TENANT_A_ADMIN_EMAIL']) { $environment['DEMO_TENANT_A_ADMIN_EMAIL'] } else { 'demo-admin@isolation-alpha.nexa.test' }
    $env:NEXA_TENANT_B_ADMIN_USERNAME = $TenantBUserName
    $env:NEXA_TENANT_B_ADMIN_PASSWORD = $tenantBPassword
    $env:NEXA_TENANT_B_ADMIN_EMAIL = if ($environment['DEMO_TENANT_B_ADMIN_EMAIL']) { $environment['DEMO_TENANT_B_ADMIN_EMAIL'] } else { 'demo-admin-b@isolation-beta.nexa.test' }

    & $PhpPath (Join-Path $root 'espocrm\bin\provision-demo-tenants.php')

    if ($LASTEXITCODE -ne 0) {
        throw 'Demo tenant administrator provisioning failed.'
    }
}
finally {
    foreach ($name in $environmentNames) {
        [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
    }
}
