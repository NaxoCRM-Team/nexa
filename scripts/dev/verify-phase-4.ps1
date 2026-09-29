[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $MariaDbClient = 'mariadb',
    [string] $EnvironmentFile = '.env',
    [string] $MigrationUser = 'root',
    [string] $MigrationPassword = '',
    [switch] $SkipRepository,
    [switch] $SkipBrowser
)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

function Read-EnvironmentFile([string] $path) {
    $values = @{}
    if (-not (Test-Path -LiteralPath $path)) { return $values }
    foreach ($line in Get-Content -LiteralPath $path) {
        if ($line -match '^\s*#' -or $line -notmatch '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') { continue }
        $values[$matches[1]] = $matches[2].Trim().Trim('"').Trim("'")
    }
    return $values
}

function Invoke-Checked([string] $file, [string[]] $arguments = @()) {
    & $file @arguments
    if ($LASTEXITCODE -ne 0) { throw "Phase 4 command failed: $file $($arguments -join ' ')" }
}

$environmentPath = if ([IO.Path]::IsPathRooted($EnvironmentFile)) { $EnvironmentFile } else { Join-Path $root $EnvironmentFile }
$environment = Read-EnvironmentFile $environmentPath
$databaseHost = if ($environment['DB_HOST']) { $environment['DB_HOST'] } else { '127.0.0.1' }
$databasePort = if ($environment['DB_PORT']) { [int] $environment['DB_PORT'] } else { 3306 }

Push-Location $root
try {
    if (-not $SkipRepository) {
        Invoke-Checked 'powershell' @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $root 'scripts\dev\verify.ps1'))
    }

    $migrationArguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $root 'tests\development\Phase4MigrationReplayTest.ps1'), '-ClientPath', $MariaDbClient, '-DatabaseHost', $databaseHost, '-Port', [string]$databasePort, '-User', $MigrationUser)
    if ($MigrationPassword -ne '') { $migrationArguments += @('-Password', $MigrationPassword) }
    Invoke-Checked 'powershell' $migrationArguments

    foreach ($suite in @(
        'tests\workflows\Phase4AcceptanceContractTest.php',
        'tests\tenant\TenantConsentGovernanceTest.php',
        'tests\tenant\TenantCookieConsentTest.php',
        'tests\tenant\TenantFormWorkspaceTest.php',
        'tests\tenant\TenantAssetWorkspaceTest.php',
        'tests\tenant\TenantLandingPageWorkspaceTest.php',
        'tests\tenant\TenantSegmentWorkspaceTest.php',
        'tests\tenant\TenantBehaviorEventTest.php',
        'tests\tenant\TenantPhase4SecurityTest.php'
    )) { Invoke-Checked $PhpPath @((Join-Path $root $suite)) }

    if (-not $SkipBrowser) {
        Write-Host 'Live Phase 4 browser tests require NEXA_LIVE_URL, NEXA_LIVE_USERNAME and NEXA_LIVE_PASSWORD.' -ForegroundColor Yellow
        Invoke-Checked 'npx' @('playwright', 'test', 'tests/browser/live-asset-workspace.spec.js', 'tests/browser/live-consent-workspace.spec.js', 'tests/browser/live-form-workspace.spec.js', 'tests/browser/live-landing-page-workspace.spec.js', 'tests/browser/live-segment-workspace.spec.js', '--project=desktop', '--project=mobile', '--workers=1')
    }

    Write-Host 'Phase 4 workstream exit gate passed.' -ForegroundColor Green
}
finally { Pop-Location }
