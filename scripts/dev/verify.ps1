[CmdletBinding()]
param([switch] $Ci)

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$failures = New-Object System.Collections.Generic.List[string]

function Fail([string] $message) {
    $script:failures.Add($message)
    Write-Host "[FAIL] $message" -ForegroundColor Red
}

function Pass([string] $message) {
    Write-Host "[PASS] $message" -ForegroundColor Green
}

$required = @(
    '.env.example', '.gitattributes', '.gitignore', '.github/CODEOWNERS',
    '.github/workflows/release.yml', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'VERSION',
    'docs/architecture/module-conventions.md', 'docs/product/screen-inventory.md',
    'docs/development/delivery-management.md', 'docs/development/wampserver-setup.md',
    'docs/development/windows-performance.md', 'docs/development/design-system.md',
    'docs/development/phase-0-release-verification.md', 'package.json', 'package-lock.json', 'playwright.config.js',
    'compose.yaml', 'scripts/dev/apply-shared-schema.ps1', 'scripts/dev/provision-demo-tenants.ps1',
    'scripts/dev/initialize-local-database.ps1', 'scripts/dev/complete-local-setup.ps1',
    'scripts/dev/setup-native-windows.ps1', 'scripts/dev/mariadb-version-policy.ps1',
    'scripts/dev/install-native-application.php', 'tests/development/MariaDbVersionPolicyTest.ps1',
    'tests/development/PortableSubfolderTest.php',
    'scripts/dev/configure-smtp.php', 'scripts/dev/configure-auth-experience.php', 'scripts/dev/install-development-seeds.php',
    'scripts/dev/verify-local-install.php',
    'database/shared/testing/0000_espocrm_9_1_9_schema.sql',
    'database/shared/migrations/0001_initial_shared_saas.sql', 'database/shared/migrations/0002_expand_espocrm_tenant_scope.sql',
    'database/shared/migrations/0003_enforce_tenant_runtime.sql', 'database/shared/migrations/0004_tenant_qualified_user_identity.sql',
    'database/shared/migrations/0005_self_service_tenant_signup.sql', 'database/shared/migrations/0006_social_identity.sql',
    'database/shared/migrations/0007_progressive_signup.sql', 'espocrm/custom/Espo/Custom/Tools/Signup/Api/PostComplete.php',
    'database/shared/migrations/0008_identity_security.sql', 'packages/sso/composer.lock',
    'database/shared/migrations/0009_unified_customer_foundation.sql',
    'database/shared/migrations/0010_enforce_core_service_ownership.sql',
    'docs/architecture/existing-schema-audit.md', 'tests/architecture/SchemaRequirementsMappingTest.php',
    'docs/development/identity-provider-testing.md', 'docs/operations/identity-incident-recovery.md',
    'tests/auth/IdentitySecurityTest.php', 'espocrm/bin/configure-identity-provider.php',
    'tests/dashboard/TenantDashboardTest.php', 'tests/browser/dashboard.spec.js', 'tests/browser/fixtures/dashboard.html',
    'espocrm/client/custom/src/views/tenant-dashboard.js',
    'database/shared/seeds/0002_two_tenant_isolation.sql', 'espocrm/bin/provision-demo-tenants.php',
    'database/shared/table-ownership-manifest.json', 'espocrm/application/Espo/Resources/tenant-table-ownership.json',
    'tests/tenant/TenantRuntimeTest.php', 'tests/tenant/OrmTenantPersistenceTest.php', 'tests/tenant/InstallationBootstrapTest.php',
    'tests/tenant/CrmDatabaseSmokeTest.php', 'tests/tenant/ServiceOwnershipDatabaseTest.php', 'tests/architecture/ModuleConventionTest.php',
    'tests/tenant/CustomerFoundationDatabaseTest.php',
    'tests/architecture/ProductRequirementsAlignmentTest.php',
    'tests/browser/shell.spec.js', 'tests/browser/fixtures/login.html', 'tests/browser/fixtures/shell.html',
    'tests/browser/fixtures/components.html',
    'tests/browser/fixtures/dialog.html',
    'espocrm/bootstrap.php', 'espocrm/application/Espo/Core/Application.php',
    'espocrm/client/lib/espo-main.js', 'espocrm/client/res/templates/login.tpl',
    'tests/signup/SignupValidatorTest.php', 'tests/signup/SmtpEnvironmentTest.php',
    'tests/signup/AuthExperienceTest.php', 'tests/browser/auth.spec.js', 'tests/browser/fixtures/auth.html',
    'espocrm/client/custom/tenant-workspace.js', 'espocrm/client/custom/css/tenant-workspace.css',
    'espocrm/client/custom/css/nexa-design-system.css',
    'espocrm/custom/Espo/Custom/Tools/App/AppParams/TenantIdentity.php',
    'espocrm/install/entry.php', 'espocrm/html/main.html', 'espocrm/public/index.php',
    'espocrm/public/landing/index.html', 'espocrm/public/landing/styles.css', 'espocrm/vendor/autoload.php'
)
foreach ($relative in $required) {
    if (Test-Path (Join-Path $root $relative)) { Pass "$relative exists" } else { Fail "$relative is missing" }
}

$jsonFiles = Get-ChildItem -LiteralPath (Join-Path $root 'espocrm\custom'), (Join-Path $root 'espocrm\client\custom') -Filter '*.json' -File -Recurse -ErrorAction SilentlyContinue
foreach ($file in $jsonFiles) {
    try { Get-Content -LiteralPath $file.FullName -Raw | ConvertFrom-Json | Out-Null; Pass "JSON $($file.Name)" }
    catch { Fail "Invalid JSON: $($file.FullName)" }
}

$powerShellFiles = Get-ChildItem -LiteralPath (Join-Path $root 'scripts\dev') -Filter '*.ps1' -File
foreach ($file in $powerShellFiles) {
    $tokens = $null
    $parseErrors = $null
    [void] [System.Management.Automation.Language.Parser]::ParseFile(
        $file.FullName,
        [ref] $tokens,
        [ref] $parseErrors
    )

    if ($parseErrors.Count -eq 0) { Pass "PowerShell $($file.Name)" }
    else { Fail "PowerShell syntax: $($file.FullName): $($parseErrors[0].Message)" }
}

try {
    & (Join-Path $root 'tests\development\MariaDbVersionPolicyTest.ps1')
    Pass 'MariaDB version policy suite'
} catch {
    Fail "MariaDB version policy suite failed: $($_.Exception.Message)"
}

$ownershipManifest = Join-Path $root 'database\shared\table-ownership-manifest.json'
try {
    $manifest = Get-Content -LiteralPath $ownershipManifest -Raw | ConvertFrom-Json
    if ($manifest.unclassifiedBehavior -ne 'deny') { Fail 'Table ownership manifest must deny unclassified tables.' }
    elseif ($manifest.tables.Count -lt 1) { Fail 'Table ownership manifest has no classified tables.' }
    elseif ($manifest.espoCoreConversion.tenantScopedTableCount -ne 133) { Fail 'Espo tenant table inventory must contain 133 tables.' }
    else {
        Pass 'Shared-schema table ownership manifest'
        $runtimeOwnership = Get-Content -LiteralPath (Join-Path $root 'espocrm/application/Espo/Resources/tenant-table-ownership.json') -Raw | ConvertFrom-Json
        $expected = @($manifest.espoCoreConversion.tenantScopedTables | Sort-Object)
        $actual = @($runtimeOwnership.tenantScopedTables | Sort-Object)
        if (Compare-Object $expected $actual) { Fail 'Runtime tenant ownership metadata differs from the database manifest.' }
        else { Pass 'Runtime tenant ownership metadata' }
    }
} catch {
    Fail "Invalid table ownership manifest: $ownershipManifest"
}
$php = Get-Command php -ErrorAction SilentlyContinue
$phpRoots = @(
    (Join-Path $root 'espocrm\custom'),
    (Join-Path $root 'espocrm\client\custom'),
    (Join-Path $root 'espocrm\application\Espo\Core\Tenant')
)
$phpFiles = Get-ChildItem -LiteralPath $phpRoots -Filter '*.php' -File -Recurse -ErrorAction SilentlyContinue
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\Core\Job\JobRunner.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\Core\Job\QueueUtil.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\Core\Job\ScheduleProcessor.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\Core\Job\ScheduleUtil.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\ORM\QueryComposer\BaseQueryComposer.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\ORM\TenantIdProvider.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\install\core\Installer.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\bin\provision-demo-tenants.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\bin\configure-identity-provider.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\auth\IdentitySecurityTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'espocrm\application\Espo\EntryPoints\ChangePassword.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'scripts\dev\install-development-seeds.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'scripts\dev\verify-local-install.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'scripts\dev\configure-smtp.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'scripts\dev\configure-auth-experience.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'scripts\dev\install-native-application.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\tenant\CrmDatabaseSmokeTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\tenant\OrmTenantPersistenceTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\tenant\ServiceOwnershipDatabaseTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\architecture\ModuleConventionTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\architecture\ProductRequirementsAlignmentTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\architecture\SchemaRequirementsMappingTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\tenant\CustomerFoundationDatabaseTest.php')
$phpFiles += Get-Item -LiteralPath (Join-Path $root 'tests\development\PortableSubfolderTest.php')
if ($php) {
    foreach ($file in $phpFiles) {
        & php -l $file.FullName *> $null
        if ($LASTEXITCODE -eq 0) { Pass "PHP $($file.Name)" } else { Fail "PHP syntax: $($file.FullName)" }
    }
} elseif ($phpFiles.Count -gt 0) {
    Fail 'PHP is required to lint product PHP files.'
}

if ($php) {
    & php (Join-Path $root 'tests\tenant\TenantRuntimeTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Tenant runtime isolation suite' } else { Fail 'Tenant runtime isolation suite failed.' }
    if (-not $Ci) {
        & php (Join-Path $root 'tests\tenant\OrmTenantPersistenceTest.php')
        if ($LASTEXITCODE -eq 0) { Pass 'ORM tenant persistence suite' } else { Fail 'ORM tenant persistence suite failed.' }
    }
    & php (Join-Path $root 'tests\tenant\InstallationBootstrapTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Installation bootstrap suite' } else { Fail 'Installation bootstrap suite failed.' }
    & php (Join-Path $root 'tests\signup\SignupValidatorTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Signup validation suite' } else { Fail 'Signup validation suite failed.' }
    & php (Join-Path $root 'tests\signup\SmtpEnvironmentTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'SMTP environment suite' } else { Fail 'SMTP environment suite failed.' }
    & php (Join-Path $root 'tests\signup\AuthExperienceTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Authentication experience suite' } else { Fail 'Authentication experience suite failed.' }
    & php (Join-Path $root 'tests\development\PortableSubfolderTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Portable subfolder suite' } else { Fail 'Portable subfolder suite failed.' }
    & php (Join-Path $root 'tests\auth\IdentitySecurityTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Identity security contract suite' } else { Fail 'Identity security contract suite failed.' }
    & php (Join-Path $root 'tests\dashboard\TenantDashboardTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Tenant dashboard contract suite' } else { Fail 'Tenant dashboard contract suite failed.' }
    & php (Join-Path $root 'tests\architecture\ModuleConventionTest.php')
    & php (Join-Path $root 'tests\architecture\ProductRequirementsAlignmentTest.php')
    & php (Join-Path $root 'tests\architecture\SchemaRequirementsMappingTest.php')
    if ($LASTEXITCODE -eq 0) { Pass 'Architecture contract suites' } else { Fail 'Architecture contract suite failed.' }
}

$tracked = & git -C $root ls-files
foreach ($prohibited in @('.env', '.env.local', 'espocrm/data/config.php', 'espocrm/data/config-internal.php')) {
    if ($tracked -contains $prohibited) { Fail "$prohibited must not be tracked" }
}
if (-not ($tracked -contains '.env')) { Pass '.env is not tracked' }

$shareablePaths = & git -C $root ls-files --cached --others --exclude-standard
# Scan exactly what Git can share. Runtime files stay ignored and the pinned vendor snapshot is
# audited at import, leaving product and team-owned files for this fast per-change check.
$privateKeyFiles = @(& git -C $root grep `
    --untracked `
    -l `
    -I `
    -E `
    'BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY' `
    -- `
    . `
    ':(exclude)espocrm/vendor/**' `
    ':(exclude)packages/sso/vendor/**')
$scanExitCode = $LASTEXITCODE
if ($scanExitCode -notin @(0, 1)) { Fail 'Private-key marker scan failed.' }
$global:LASTEXITCODE = 0
$keyFileExtensions = @('.key', '.pem', '.p12', '.pfx')
$keyFiles = $shareablePaths |
    Where-Object { $keyFileExtensions -contains [System.IO.Path]::GetExtension($_).ToLowerInvariant() } |
    ForEach-Object { Join-Path $root $_ }
if ($privateKeyFiles -or $keyFiles) { Fail 'A private key or key file exists in a shareable path.' } else { Pass 'No private keys found' }

$migrationNames = Get-ChildItem -LiteralPath (Join-Path $root 'database') -Filter '*.sql' -File -Recurse | Select-Object -ExpandProperty Name
foreach ($name in $migrationNames) {
    if ($name -notmatch '^\d{4}_[a-z0-9_]+\.sql$') { Fail "Migration/seed filename is not canonical: $name" }
}

if (-not $Ci -and (Get-Command docker -ErrorAction SilentlyContinue)) {
    & docker compose --project-directory $root config --quiet
    if ($LASTEXITCODE -eq 0) { Pass 'Compose configuration' } else { Fail 'Compose configuration is invalid' }
}

if ($failures.Count -gt 0) {
    Write-Host "$($failures.Count) verification check(s) failed." -ForegroundColor Red
    exit 1
}

Write-Host 'Repository verification passed.' -ForegroundColor Green
exit 0
