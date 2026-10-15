param(
    [string] $BaseUrl = 'http://localhost/nexa',
    [string] $PhpPath = 'php',
    [string] $Projects = 'desktop,mobile',
    [string] $ApprovedWebhookUrl = '',
    [ValidateSet('Automation','Programmable','Closure','Experiment','Scoring','Abm','Personalization','Phase7Workers')]
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
$names = @('NEXA_AUTOMATION_ACCEPTANCE','NEXA_LIVE_URL','NEXA_PHP_PATH','NEXA_AUTOMATION_WEBHOOK_URL','NEXA_PROGRAMMABLE_ACCEPTANCE','NEXA_POSTAL_CAPTURE_ACCEPTANCE','NEXA_PHASE6_CLOSURE_ACCEPTANCE')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
Push-Location $root
try {
    $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
    $env:NEXA_LIVE_URL = $BaseUrl.TrimEnd('/')
    $env:NEXA_PHP_PATH = $PhpPath
    $env:NEXA_AUTOMATION_WEBHOOK_URL = $ApprovedWebhookUrl
    $env:NEXA_PROGRAMMABLE_ACCEPTANCE = if ($Suite -eq 'Programmable') {'1'} else {''}
    if ($Suite -in @('Closure','Experiment') -and -not $LocalPostalCapture) { throw 'Combined email acceptance requires explicit -LocalPostalCapture.' }
    $env:NEXA_PHASE6_CLOSURE_ACCEPTANCE = if ($Suite -in @('Closure','Experiment')) {'1'} else {''}
    $env:NEXA_POSTAL_CAPTURE_ACCEPTANCE = if ($LocalPostalCapture -and $Suite -in @('Programmable','Closure','Experiment')) {'1'} else {''}
    $spec = switch ($Suite) { 'Closure' {'tests/browser/live-phase6-workflow-email.spec.js'} 'Experiment' {'tests/browser/live-phase6-email-experiment.spec.js'} 'Scoring' {'tests/browser/live-scoring-workspace.spec.js'} 'Abm' {'tests/browser/live-abm-workspace.spec.js'} 'Personalization' {'tests/browser/live-landing-personalization.spec.js'} default {'tests/browser/live-automation-acceptance.spec.js'} }
    if ($Suite -eq 'Phase7Workers') { $spec = 'tests/browser/live-phase7-workers.spec.js' }
    $arguments = @('playwright','test',$spec,'--workers=1')
    if ($Suite -notin @('Closure','Experiment','Scoring','Abm','Personalization','Phase7Workers')) { $arguments += @('--grep', $(if ($Suite -eq 'Programmable') {'programmable email'} else {'authenticated automation|approved public HTTPS webhook'})) }
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
