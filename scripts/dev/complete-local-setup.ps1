[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $EnvironmentFile = '.env'
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$verifyInstall = Join-Path $PSScriptRoot 'verify-local-install.php'

if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) {
    throw "PHP executable not found: $PhpPath"
}

& $PhpPath $verifyInstall --before-demo
if ($LASTEXITCODE -ne 0) {
    throw 'Application installation is incomplete. Run setup-native-windows.ps1 before loading demo data.'
}

& $PhpPath (Join-Path $PSScriptRoot 'configure-smtp.php') "--env=$EnvironmentFile"
if ($LASTEXITCODE -ne 0) { throw 'System SMTP configuration failed.' }

& $PhpPath (Join-Path $PSScriptRoot 'configure-auth-experience.php') "--env=$EnvironmentFile"
if ($LASTEXITCODE -ne 0) { throw 'Authentication experience configuration failed.' }

& $PhpPath (Join-Path $PSScriptRoot 'install-development-seeds.php')
if ($LASTEXITCODE -ne 0) { throw 'Development seed installation failed.' }

# Espo stores searchable countries as records; the base SQL fixture contains
# the table but not its locale-backed catalogue.
& $PhpPath (Join-Path $root 'espocrm\bin\populate-address-countries.php')
if ($LASTEXITCODE -ne 0) { throw 'Address country catalogue installation failed.' }

& (Join-Path $PSScriptRoot 'provision-demo-tenants.ps1') `
    -Mode Local `
    -PhpPath $PhpPath `
    -EnvironmentFile $EnvironmentFile
if ($LASTEXITCODE -ne 0) { throw 'Demo tenant provisioning failed.' }

Push-Location (Join-Path $root 'espocrm')
try {
    & $PhpPath rebuild.php
    if ($LASTEXITCODE -ne 0) { throw 'Application rebuild failed.' }

    & $PhpPath clear_cache.php
    if ($LASTEXITCODE -ne 0) { throw 'Application cache clearing failed.' }

    # Frontend extension files use appTimestamp for browser cache invalidation.
    & $PhpPath command.php update-app-timestamp
    if ($LASTEXITCODE -ne 0) { throw 'Application asset timestamp update failed.' }
}
finally {
    Pop-Location
}

& $PhpPath $verifyInstall
if ($LASTEXITCODE -ne 0) { throw 'Local installation data verification failed.' }

& (Join-Path $PSScriptRoot 'verify.ps1') -Ci
if ($LASTEXITCODE -ne 0) { throw 'Repository verification failed.' }

Write-Host 'Local Nexa installation, demo tenants and demo CRM data are ready.' -ForegroundColor Green
