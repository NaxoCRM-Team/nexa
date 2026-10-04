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
    if ($LASTEXITCODE -ne 0) { throw "Phase 5 command failed: $file $($arguments -join ' ')" }
}

function Resolve-MariaDbClient([string] $requested) {
    $command = Get-Command $requested -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    $candidate = Get-ChildItem 'C:\wamp64\bin\mariadb' -Directory -ErrorAction SilentlyContinue |
        ForEach-Object { Join-Path $_.FullName 'bin\mariadb.exe' } |
        Where-Object { Test-Path -LiteralPath $_ } |
        Sort-Object -Descending | Select-Object -First 1
    if ($candidate) { return $candidate }
    throw 'A MariaDB client is required to resolve the live tenant login email.'
}

function Resolve-LiveLoginIdentifier([string] $identifier, [hashtable] $settings) {
    if ($identifier -match '@') { return $identifier }
    if (-not $identifier) { return '' }

    $client = Resolve-MariaDbClient $MariaDbClient
    $databaseUser = if ($settings['DB_USER']) { $settings['DB_USER'] } else { $MigrationUser }
    $databasePassword = if ($settings.ContainsKey('DB_PASSWORD')) { $settings['DB_PASSWORD'] } else { $MigrationPassword }
    $escapedIdentifier = $identifier.Replace("'", "''")
    $arguments = @(
        '--batch', '--skip-column-names',
        "--host=$databaseHost", "--port=$databasePort", "--user=$databaseUser",
        $settings['DB_NAME'], '-e',
        "SELECT login_email FROM user WHERE user_name='$escapedIdentifier' AND login_email IS NOT NULL AND login_email<>'' AND deleted=0 LIMIT 1"
    )
    if (-not $databasePassword) { $arguments = @('--skip-password') + $arguments }
    $previousDatabasePassword = $env:MYSQL_PWD
    try {
        $env:MYSQL_PWD = if ($databasePassword) { $databasePassword } else { $null }
        $loginEmail = @(& $client @arguments)
        if ($LASTEXITCODE -ne 0 -or -not $loginEmail[0]) {
            throw 'The live tenant administrator does not have a login email.'
        }
        return [string] $loginEmail[0]
    }
    finally { $env:MYSQL_PWD = $previousDatabasePassword }
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

    $migrationArguments = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $root 'tests\development\Phase5MigrationReplayTest.ps1'), '-ClientPath', $MariaDbClient, '-DatabaseHost', $databaseHost, '-Port', [string]$databasePort, '-User', $MigrationUser)
    if ($MigrationPassword -ne '') { $migrationArguments += @('-Password', $MigrationPassword) }
    Invoke-Checked 'powershell' $migrationArguments

    foreach ($suite in @(
        'tests\workflows\Phase5AcceptanceContractTest.php',
        'tests\workflows\BehaviorEventContractTest.php',
        'tests\workflows\BehaviorEventValidationTest.php',
        'tests\workflows\PublicEventCollectorContractTest.php',
        'tests\workflows\CustomerBehaviorTimelineContractTest.php',
        'tests\workflows\EventRetentionContractTest.php',
        'tests\tenant\TenantBehaviorEventTest.php',
        'tests\tenant\TenantBehaviorEventReplayTest.php',
        'tests\tenant\TenantPublicEventCollectorTest.php',
        'tests\tenant\TenantBehaviorIdentityBackfillTest.php',
        'tests\tenant\TenantEventRetentionTest.php'
    )) { Invoke-Checked $PhpPath @((Join-Path $root $suite)) }

    if (-not $SkipBrowser) {
        $previousUrl = $env:NEXA_LIVE_URL
        $previousUsername = $env:NEXA_LIVE_USERNAME
        $previousPassword = $env:NEXA_LIVE_PASSWORD
        try {
            if (-not $env:NEXA_LIVE_URL) { $env:NEXA_LIVE_URL = $environment['ESPOCRM_SITE_URL'] }
            if (-not $env:NEXA_LIVE_USERNAME) {
                $env:NEXA_LIVE_USERNAME = Resolve-LiveLoginIdentifier $environment['DEMO_TENANT_A_ADMIN_USERNAME'] $environment
            }
            if (-not $env:NEXA_LIVE_PASSWORD) { $env:NEXA_LIVE_PASSWORD = $environment['DEMO_TENANT_A_ADMIN_PASSWORD'] }
            if (-not $env:NEXA_LIVE_URL -or -not $env:NEXA_LIVE_USERNAME -or -not $env:NEXA_LIVE_PASSWORD) {
                throw 'Live Phase 5 browser credentials are not configured.'
            }
            Invoke-Checked 'npx' @('playwright', 'test', 'tests/browser/live-tracking-retention.spec.js', '--project=desktop', '--project=mobile', '--workers=1')
        }
        finally {
            $env:NEXA_LIVE_URL = $previousUrl
            $env:NEXA_LIVE_USERNAME = $previousUsername
            $env:NEXA_LIVE_PASSWORD = $previousPassword
        }
    }

    Write-Host 'Phase 5 workstream exit gate passed.' -ForegroundColor Green
}
finally { Pop-Location }
