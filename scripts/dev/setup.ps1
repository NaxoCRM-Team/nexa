[CmdletBinding()]
param(
    [switch] $SkipStart
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $root '.env'
$envExample = Join-Path $root '.env.example'

function New-RandomSecret {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).Replace('+', 'A').Replace('/', 'B').TrimEnd('=')
}

function New-RandomEncryptionKey {
    $bytes = New-Object byte[] 32
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes)
}

function Get-EnvValue([string] $Content, [string] $Name) {
    $match = [regex]::Match($Content, '(?m)^' + [regex]::Escape($Name) + '=(.*)$')
    if ($match.Success) { return $match.Groups[1].Value.Trim() }
    return $null
}

function Add-EnvValue([string] $Content, [string] $Name, [string] $Value) {
    if (Get-EnvValue $Content $Name) { return $Content }
    return $Content.TrimEnd("`r", "`n") + "`r`n${Name}=${Value}`r`n"
}

if (-not (Test-Path $envPath)) {
    $content = Get-Content -LiteralPath $envExample -Raw
    $content = $content.Replace('replace_with_a_long_random_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_different_long_random_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_third_long_random_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_strong_admin_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_tenant_a_admin_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_tenant_b_admin_password', (New-RandomSecret))
    $content = $content.Replace('replace_with_a_base64_32_byte_key', (New-RandomEncryptionKey))
    Set-Content -LiteralPath $envPath -Value $content -Encoding ASCII
    Write-Host 'Created .env with local random credentials. Do not commit it.' -ForegroundColor Green
}
else {
    $content = Get-Content -LiteralPath $envPath -Raw
    $legacyUserName = Get-EnvValue $content 'DEMO_TENANT_ADMIN_USERNAME'
    $legacyPassword = Get-EnvValue $content 'DEMO_TENANT_ADMIN_PASSWORD'

    $content = Add-EnvValue $content 'DEMO_TENANT_A_ADMIN_USERNAME' $(if ($legacyUserName) { $legacyUserName } else { 'demo-admin' })
    $content = Add-EnvValue $content 'DEMO_TENANT_A_ADMIN_PASSWORD' $(if ($legacyPassword) { $legacyPassword } else { New-RandomSecret })
    $content = Add-EnvValue $content 'DEMO_TENANT_B_ADMIN_USERNAME' 'demo-admin-b'
    $content = Add-EnvValue $content 'DEMO_TENANT_B_ADMIN_PASSWORD' (New-RandomSecret)
    $content = Add-EnvValue $content 'NEXA_AUTH_SECRET_KEY' (New-RandomEncryptionKey)
    $content = [regex]::Replace($content, '(?m)^DEMO_TENANT_ADMIN_(USERNAME|PASSWORD)=.*\r?\n?', '')
    Set-Content -LiteralPath $envPath -Value $content.TrimEnd("`r", "`n") -Encoding ASCII
    Write-Host 'Kept the existing .env and ensured separate demo tenant credentials.'
}

& (Join-Path $PSScriptRoot 'bootstrap-espocrm.ps1')
& (Join-Path $PSScriptRoot 'check-environment.ps1')

if (-not $SkipStart) {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        throw 'Docker is unavailable. Use -SkipStart for an XAMPP or WampServer setup.'
    }
    & docker compose --project-directory $root up -d --wait
    if ($LASTEXITCODE -ne 0) { throw 'Docker Compose did not become healthy.' }
    & (Join-Path $PSScriptRoot 'apply-shared-schema.ps1') -Mode Docker -IncludeDevelopmentSeeds
    if ($LASTEXITCODE -ne 0) { throw 'Shared-schema migration failed.' }
    & (Join-Path $PSScriptRoot 'provision-demo-tenants.ps1') -Mode Docker `
        -TenantAUserName (Get-EnvValue $content 'DEMO_TENANT_A_ADMIN_USERNAME') `
        -TenantBUserName (Get-EnvValue $content 'DEMO_TENANT_B_ADMIN_USERNAME')
    if ($LASTEXITCODE -ne 0) { throw 'Demo tenant provisioning failed.' }
    & docker compose --project-directory $root ps
}

Write-Host 'Local setup is ready.' -ForegroundColor Green
