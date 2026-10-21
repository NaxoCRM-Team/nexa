[CmdletBinding()]
param(
    [string] $PhpPath = 'php',
    [switch] $Live,
    [string] $BaseUrl = 'http://localhost/nexa',
    [ValidateSet('desktop','tablet','tablet-landscape','mobile','mobile-landscape')]
    [string[]] $Project = @('desktop','tablet','tablet-landscape','mobile','mobile-landscape')
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) { throw 'PHP executable not found.' }
if ($Live -and ([uri]$BaseUrl).Host -notin @('localhost','127.0.0.1')) { throw 'Use the local WAMP application.' }
$names = @('NEXA_AUTOMATION_ACCEPTANCE','NEXA_LIVE_URL','NEXA_PHP_PATH')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name,'Process') }
$results = [System.Collections.Generic.List[object]]::new()
function Invoke-Package([string] $Name, [scriptblock] $Run) {
    $clock = [Diagnostics.Stopwatch]::StartNew()
    Write-Host "=== $Name ==="
    & $Run
    if ($LASTEXITCODE -ne 0) { throw "$Name failed with exit code $LASTEXITCODE." }
    $results.Add([pscustomobject]@{package=$Name;status='passed';seconds=[math]::Round($clock.Elapsed.TotalSeconds,2)})
}
Push-Location $root
try {
    Invoke-Package 'Fail-closed migration ledger responses' { & powershell -NoProfile -ExecutionPolicy Bypass -File tests/database/MigrationRunnerResponseTest.ps1 }
    # Database suites roll back; HTTP fixtures commit. Never overlap these phases.
    Invoke-Package 'Shared native email' { & powershell -ExecutionPolicy Bypass -File scripts/dev/verify-conversations.ps1 -PhpPath $PhpPath }
    Invoke-Package 'Chat and privacy' { & powershell -ExecutionPolicy Bypass -File scripts/dev/verify-live-chat.ps1 -PhpPath $PhpPath }
    Invoke-Package 'Messaging, recovery, media and permissions' { & powershell -ExecutionPolicy Bypass -File scripts/dev/verify-messaging-providers.ps1 -PhpPath $PhpPath -Database }
    Invoke-Package 'Native queue fairness and failure recovery' { & $PhpPath tests/tenant/TenantQueueFairnessTest.php }
    Invoke-Package 'Capacity and sustained cross-channel routing' { & $PhpPath tests/tenant/Phase8CombinedCapacityTest.php }
    Invoke-Package 'One Contact across four channels' { & $PhpPath tests/tenant/Phase8CrossChannelTest.php }
    if ($Live) {
        $env:NEXA_AUTOMATION_ACCEPTANCE='1'
        $env:NEXA_LIVE_URL=$BaseUrl.TrimEnd('/')
        $env:NEXA_PHP_PATH=(Get-Command $PhpPath).Source
        $specs=@(
            'tests/browser/live-conversation-workspace.spec.js',
            'tests/browser/live-chat.spec.js',
            'tests/browser/live-chat-privacy.spec.js',
            'tests/browser/live-messaging-connections.spec.js',
            'tests/browser/live-sms-dispatch.spec.js',
            'tests/browser/live-whatsapp-settings.spec.js',
            'tests/browser/live-whatsapp-dispatch.spec.js',
            'tests/browser/live-messenger.spec.js',
            'tests/browser/live-conversation-routing.spec.js',
            'tests/browser/live-incoming-media.spec.js'
        )
        $projectArgs=@($Project | ForEach-Object { "--project=$_" })
        Invoke-Package 'Authenticated responsive and cross-channel acceptance' { & npx playwright test @specs @projectArgs --workers=1 --max-failures=1 }
    }
    Invoke-Package 'Repository verification' { & powershell -ExecutionPolicy Bypass -File scripts/dev/verify.ps1 -Ci }
    Invoke-Package 'Whitespace' { & git diff --check }
    $results | Format-Table -AutoSize
    Write-Warning 'Local acceptance only: fake provider transports. Public HTTPS callbacks, real provider delivery and real Cloudflare deletion remain separate external acceptance.'
} finally {
    foreach($name in $names){[Environment]::SetEnvironmentVariable($name,$previous[$name],'Process')}
    Pop-Location
}
