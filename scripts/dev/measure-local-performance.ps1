[CmdletBinding()]
param(
    [string] $BaseUrl = 'http://nexa.local',
    [ValidateRange(3, 20)] [int] $Samples = 5,
    [ValidateRange(0.2, 30)] [double] $WarmLimitSeconds = 2.0,
    [string] $EnvironmentFile = '.env'
)

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Net.Http
$client = [System.Net.Http.HttpClient]::new()
$client.Timeout = [TimeSpan]::FromSeconds(30)

function Measure-Endpoint([string] $Label, [string] $Url, [string] $Authorization = '') {
    $values = @()
    for ($index = 0; $index -lt ($Samples + 1); $index++) {
        $request = [System.Net.Http.HttpRequestMessage]::new('GET', $Url)
        if ($Authorization) { $request.Headers.TryAddWithoutValidation('Authorization', $Authorization) | Out-Null }
        $timer = [System.Diagnostics.Stopwatch]::StartNew()
        $response = $client.SendAsync($request).GetAwaiter().GetResult()
        $response.Content.ReadAsByteArrayAsync().GetAwaiter().GetResult() | Out-Null
        $timer.Stop()
        if ($index -gt 0) { $values += $timer.Elapsed.TotalSeconds }
        if (-not $response.IsSuccessStatusCode) { throw "$Label returned HTTP $([int] $response.StatusCode)." }
    }
    $ordered = @($values | Sort-Object)
    $average = ($values | Measure-Object -Average).Average
    $p95Index = [Math]::Min($ordered.Count - 1, [Math]::Ceiling($ordered.Count * 0.95) - 1)
    $p95 = $ordered[$p95Index]
    Write-Host ("{0}: average {1:N3}s, p95 {2:N3}s over {3} warm requests" -f $Label, $average, $p95, $Samples)
    return $p95
}

try {
    $loginP95 = Measure-Endpoint 'Shared login' "$($BaseUrl.TrimEnd('/'))/login"
    if ($loginP95 -gt $WarmLimitSeconds) {
        throw "Shared login p95 $($loginP95.ToString('N3'))s exceeds the $WarmLimitSeconds second limit."
    }

    if (Test-Path -LiteralPath $EnvironmentFile) {
        $content = Get-Content -LiteralPath $EnvironmentFile -Raw
        $user = [regex]::Match($content, '(?m)^DEMO_TENANT_A_ADMIN_USERNAME=(.*)$').Groups[1].Value.Trim()
        $password = [regex]::Match($content, '(?m)^DEMO_TENANT_A_ADMIN_PASSWORD=(.*)$').Groups[1].Value.Trim()
        if ($user -and $password) {
            $token = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("${user}:${password}"))
            Measure-Endpoint 'Tenant API' "$($BaseUrl.TrimEnd('/'))/api/v1/App/user" "Basic $token" | Out-Null
        }
    }
    Write-Host 'Local performance check passed.' -ForegroundColor Green
}
finally {
    $client.Dispose()
}
