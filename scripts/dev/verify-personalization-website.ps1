param([string] $PhpPath = 'php', [string] $Projects = 'desktop,mobile')
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$names = @('NEXA_AUTOMATION_ACCEPTANCE','NEXA_WEBSITE_ACCEPTANCE','NEXA_PHP_PATH')
$previous = @{}
foreach ($name in $names) { $previous[$name] = [Environment]::GetEnvironmentVariable($name, 'Process') }
Push-Location $root
try {
    $env:NEXA_AUTOMATION_ACCEPTANCE = '1'
    $env:NEXA_WEBSITE_ACCEPTANCE = '1'
    $env:NEXA_PHP_PATH = (Get-Command $PhpPath -ErrorAction Stop).Source
    $arguments = @('playwright','test','tests/browser/live-personalization-website.spec.js','--workers=1')
    foreach ($project in $Projects.Split(',')) { $arguments += "--project=$($project.Trim())" }
    & npx @arguments
    if ($LASTEXITCODE -ne 0) { throw 'Local HTTPS website acceptance failed.' }
} finally {
    Pop-Location
    foreach ($name in $names) { [Environment]::SetEnvironmentVariable($name, $previous[$name], 'Process') }
}
