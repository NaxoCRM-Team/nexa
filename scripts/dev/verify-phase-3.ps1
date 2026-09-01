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

function Invoke-PhpSuite([string] $relativePath) {
    & $PhpPath (Join-Path $root $relativePath)
    if ($LASTEXITCODE -ne 0) { throw "Phase 3 suite failed: $relativePath" }
}

function Invoke-PowerShellSuite([string] $scriptPath, [string[]] $arguments = @()) {
    $hostPath = (Get-Process -Id $PID).Path
    $process = Start-Process `
        -FilePath $hostPath `
        -ArgumentList (@('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $scriptPath) + $arguments) `
        -NoNewWindow `
        -Wait `
        -PassThru
    return $process.ExitCode
}

$environmentPath = if ([IO.Path]::IsPathRooted($EnvironmentFile)) {
    $EnvironmentFile
} else {
    Join-Path $root $EnvironmentFile
}
$environment = Read-EnvironmentFile $environmentPath
$databaseHost = if ($environment['DB_HOST']) { $environment['DB_HOST'] } else { '127.0.0.1' }
$databasePort = if ($environment['DB_PORT']) { [int] $environment['DB_PORT'] } else { 3306 }

Push-Location $root
try {
    if (-not $SkipRepository) {
        $exitCode = Invoke-PowerShellSuite (Join-Path $root 'scripts\dev\verify.ps1') @('-Ci')
        if ($exitCode -ne 0) { throw 'Repository and Phase 3 contract verification failed.' }
    }

    $migrationArguments = @(
        '-ClientPath', $MariaDbClient,
        '-DatabaseHost', $databaseHost,
        '-Port', [string] $databasePort,
        '-User', $MigrationUser
    )
    if ($MigrationPassword -ne '') { $migrationArguments += @('-Password', $MigrationPassword) }
    $exitCode = Invoke-PowerShellSuite `
        (Join-Path $root 'tests\development\Phase3MigrationReplayTest.ps1') `
        $migrationArguments
    if ($exitCode -ne 0) { throw 'Phase 3 clean-install or incremental migration replay failed.' }

    $exitCode = Invoke-PowerShellSuite `
        (Join-Path $root 'scripts\dev\provision-demo-tenants.ps1') `
        @('-Mode', 'Local', '-PhpPath', $PhpPath, '-EnvironmentFile', $environmentPath)
    if ($exitCode -ne 0) { throw 'Phase 3 demo fixture provisioning failed.' }

    foreach ($suite in @(
        'tests\tenant\OrmTenantPersistenceTest.php',
        'tests\tenant\CustomerFoundationRuntimeTest.php',
        'tests\tenant\TenantNativeMergeTest.php',
        'tests\tenant\TenantLeadConversionTest.php',
        'tests\tenant\TenantSalesPipelineTest.php',
        'tests\tenant\TenantCurrencyIsolationTest.php',
        'tests\tenant\TenantCaseSlaTest.php',
        'tests\tenant\CaseSlaApiJobTest.php',
        'tests\tenant\DemoCasePortalFixtureTest.php'
    )) {
        Invoke-PhpSuite $suite
    }

    if (-not $SkipBrowser) {
        & npm run test:shell
        if ($LASTEXITCODE -ne 0) { throw 'Phase 3 browser accessibility and regression suite failed.' }
    }

    Write-Host 'Phase 3 combined exit gate passed.' -ForegroundColor Green
}
finally {
    Pop-Location
}
