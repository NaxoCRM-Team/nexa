[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [switch] $Live,
    [string] $BaseUrl = 'http://localhost/nexa'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) { throw 'PHP executable not found.' }
if ($Live -and ([uri] $BaseUrl).Host -notin @('localhost', '127.0.0.1')) {
    throw 'Committed conversation fixtures are allowed only against the local development application.'
}
$variables = @('NEXA_AUTOMATION_ACCEPTANCE', 'NEXA_LIVE_URL', 'NEXA_PHP_PATH')
$previous = @{}
foreach ($name in $variables) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
Push-Location $root
try {
    & $PhpPath tests/tenant/TenantConversationTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Native conversation acceptance failed.' }
    & $PhpPath tests/tenant/TenantConversationHistoryTest.php
    if ($LASTEXITCODE -ne 0) { throw 'Historical conversation acceptance failed.' }
    if ($Live) {
        $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
        $env:NEXA_LIVE_URL = $BaseUrl.TrimEnd('/')
        $env:NEXA_PHP_PATH = (Get-Command $PhpPath).Source
        & npx playwright test tests/browser/live-conversation-workspace.spec.js --workers=1
        if ($LASTEXITCODE -ne 0) { throw 'Authenticated conversation browser acceptance failed.' }
    }
    Write-Host 'Shared-email conversation package acceptance passed.'
    Write-Warning 'This is not the full Phase 8 exit gate or external mailbox delivery acceptance.'
} finally {
    foreach ($name in $variables) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
    Pop-Location
}
