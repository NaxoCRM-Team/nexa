param(
    [string] $BaseUrl = 'http://localhost/nexa',
    [string] $PhpPath = 'php',
    [string] $Projects = 'desktop,mobile',
    [string] $ApprovedWebhookUrl = '',
    [ValidateSet('Automation','Programmable')]
    [string] $Suite = 'Automation',
    [switch] $LocalPostalCapture
)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$uri = [Uri] $BaseUrl
if ($uri.Host -notin @('localhost', '127.0.0.1')) {
    throw 'Committed acceptance fixtures are allowed only against the local application.'
}
if (-not (Get-Command $PhpPath -ErrorAction SilentlyContinue)) { throw 'PHP executable not found.' }
$names = @('NEXA_AUTOMATION_ACCEPTANCE','NEXA_LIVE_URL','NEXA_PHP_PATH','NEXA_AUTOMATION_WEBHOOK_URL','NEXA_PROGRAMMABLE_ACCEPTANCE','NEXA_POSTAL_CAPTURE_ACCEPTANCE')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
Push-Location $root
try {
    $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
    $env:NEXA_LIVE_URL = $BaseUrl.TrimEnd('/')
    $env:NEXA_PHP_PATH = $PhpPath
    $env:NEXA_AUTOMATION_WEBHOOK_URL = $ApprovedWebhookUrl
    $env:NEXA_PROGRAMMABLE_ACCEPTANCE = if ($Suite -eq 'Programmable') {'1'} else {''}
    $env:NEXA_POSTAL_CAPTURE_ACCEPTANCE = if ($LocalPostalCapture -and $Suite -eq 'Programmable') {'1'} else {''}
    $arguments = @('playwright','test','tests/browser/live-automation-acceptance.spec.js','--workers=1')
    $arguments += @('--grep', $(if ($Suite -eq 'Programmable') {'programmable email'} else {'authenticated automation|approved public HTTPS webhook'}))
    foreach ($project in $Projects.Split(',')) { $arguments += "--project=$($project.Trim())" }
    & npx @arguments
    if ($LASTEXITCODE -ne 0) { throw "$Suite acceptance failed." }
    if ($Suite -eq 'Automation' -and -not $ApprovedWebhookUrl) {
        Write-Warning 'Public webhook delivery was skipped: no owner-approved HTTPS endpoint was supplied. Local acceptance does not prove external delivery.'
    }
}
finally {
    Pop-Location
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
}
