[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [string] $MigrationClientPath = '',
    [switch] $FreshInstallation,
    [switch] $WebsiteIntegration,
    [switch] $Live,
    [string] $BaseUrl = 'http://localhost/nexa'
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) { throw 'PHP executable not found.' }
$native = @(
    'TenantQueueFairnessTest.php',
    'TenantScoringDefinitionTest.php',
    'TenantScoringModelTest.php',
    'TenantAbmTest.php',
    'TenantMarketingConditionalContentTest.php',
    'TenantLandingPersonalizationTest.php',
    'TenantCustomerPersonalizationTest.php',
    'TenantPersonalizationHistoryTest.php'
)
$browser = @(
    'tests/browser/scoring-workspace.spec.js',
    'tests/browser/scoring-crm-timeline.spec.js',
    'tests/browser/abm-workspace.spec.js',
    'tests/browser/marketing-conditional-content.spec.js',
    'tests/browser/landing-personalization.spec.js',
    'tests/browser/personalization-history.spec.js'
)
Push-Location $root
try {
    if ($MigrationClientPath) {
        & "$root/tests/development/Phase7MigrationReplayTest.ps1" -ClientPath $MigrationClientPath
    }
    if ($FreshInstallation) {
        if (!$MigrationClientPath) { throw 'Fresh installation acceptance requires -MigrationClientPath.' }
        & "$root/tests/development/Phase7InstallationAcceptance.ps1" -PhpPath $PhpPath -ClientPath $MigrationClientPath
    }
    # Native suites use rollback fixtures on one database and must not overlap.
    foreach ($test in $native) {
        Write-Host "[Phase 7 native] $test"
        & $PhpPath "tests/tenant/$test"
        if ($LASTEXITCODE -ne 0) { throw "Phase 7 native acceptance failed: $test" }
    }
    & npx playwright test @browser --workers=1
    if ($LASTEXITCODE -ne 0) { throw 'Phase 7 responsive browser acceptance failed.' }
    if ($Live) {
        foreach ($suite in @('Scoring', 'Abm', 'Personalization')) {
            & "$PSScriptRoot/verify-automation-acceptance.ps1" -Suite $suite -PhpPath $PhpPath -BaseUrl $BaseUrl -Projects 'desktop,mobile'
        }
        & "$PSScriptRoot/verify-automation-acceptance.ps1" -Suite Phase7Workers -PhpPath $PhpPath -BaseUrl $BaseUrl -Projects 'desktop'
    }
    if ($WebsiteIntegration) {
        & "$PSScriptRoot/verify-personalization-website.ps1" -PhpPath $PhpPath
    }
    Write-Host 'Combined Phase 7 functional acceptance passed.'
    Write-Warning 'Migration replay requires -MigrationClientPath. Worker, capacity and global selector checks provide bounded local evidence, not production throughput or public website acceptance.'
} finally { Pop-Location }
