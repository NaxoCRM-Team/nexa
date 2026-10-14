[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$state = Join-Path $root '.local\postal'
$config = Join-Path $state 'config'
New-Item -ItemType Directory -Force -Path $config | Out-Null
$envFile = Join-Path $state '.env'
function New-Secret {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return ([BitConverter]::ToString($bytes)).Replace('-', '').ToLowerInvariant()
}
if (!(Test-Path -LiteralPath $envFile)) {
    [IO.File]::WriteAllText($envFile, "POSTAL_DB_PASSWORD=$(New-Secret)`nPOSTAL_RAILS_SECRET_KEY=$(New-Secret)`nPOSTAL_LOCAL_ADMIN_PASSWORD=$(New-Secret)`n")
}
$yaml = Join-Path $config 'postal.yml'
if (!(Test-Path -LiteralPath $yaml)) { [IO.File]::WriteAllText($yaml, "version: 2`n") }
$compose = @('compose', '--project-directory', $root, '--env-file', $envFile, '-f', (Join-Path $root 'compose.postal.yaml'))
function Invoke-Compose([string[]] $Arguments) {
    & docker @compose @Arguments
    if ($LASTEXITCODE -ne 0) { throw "Postal Docker command failed: $($Arguments -join ' ')" }
}
Invoke-Compose @('pull')
Invoke-Compose @('up', '-d', '--wait', 'postal-database', 'mailpit')
if (!(Test-Path -LiteralPath (Join-Path $config 'signing.key'))) {
    Invoke-Compose @('run', '--rm', '--no-deps', '--entrypoint', 'openssl', 'postal-runner', 'genrsa', '-out', '/config/signing.key', '2048')
}
Invoke-Compose @('run', '--rm', 'postal-runner', 'postal', 'initialize')
Invoke-Compose @('run', '--rm', 'postal-runner', 'bundle', 'exec', 'rails', 'runner', '/bootstrap/bootstrap.rb')
Invoke-Compose @('up', '-d', 'postal-event-capture', 'postal-web', 'postal-worker', 'postal-smtp', 'postal-proxy')
$ready = $false
for ($attempt = 0; $attempt -lt 60; $attempt++) {
    $ErrorActionPreference = 'Continue'
    & docker @compose cp 'postal-proxy:/data/caddy/pki/authorities/local/root.crt' (Join-Path $state 'root.crt') 2>$null
    $ErrorActionPreference = 'Stop'
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 2
}
if (!$ready) { throw 'Postal proxy did not generate its local CA certificate. Check docker compose logs.' }
Write-Host 'Postal: https://localhost:8443 | Captured mail: http://localhost:8026'
Write-Host 'Local credentials and CA certificate are under .local/postal (ignored by Git).'
