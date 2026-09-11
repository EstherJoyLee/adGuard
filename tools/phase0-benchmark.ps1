param(
    [ValidateRange(20,400)][int]$Samples = 200,
    [ValidateRange(1,64)][int]$Warmup = 20,
    [string]$OutputPath = '',
    [string]$Php = 'php'
)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
if ($OutputPath -eq '') { $OutputPath = Join-Path $taskRoot 'docs/adguard/phase0/http-baseline.json' }
$taskTemp = Join-Path ([IO.Path]::GetTempPath()) ('adguard-phase0-http-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $taskTemp | Out-Null
$taskOldFlag = $env:PHASE0_BASELINE
$taskOldBase = $env:PHASE0_BASE_DIR
$taskServer = $null
$taskClient = $null

function Get-Phase0Stats($Values) {
    $taskSorted = @($Values | Sort-Object)
    [ordered]@{
        count=$taskSorted.Count
        mean=($taskSorted | Measure-Object -Average).Average
        p50=$taskSorted[[Math]::Ceiling($taskSorted.Count * 0.50)-1]
        p95=$taskSorted[[Math]::Ceiling($taskSorted.Count * 0.95)-1]
        p99=$taskSorted[[Math]::Ceiling($taskSorted.Count * 0.99)-1]
        min=$taskSorted[0]
        max=$taskSorted[-1]
    }
}

function Invoke-Phase0Request([string]$Scenario, [int]$Slot) {
    $taskWatch = [Diagnostics.Stopwatch]::StartNew()
    $taskResponse = $taskClient.GetAsync("$taskUrl/phase0-page.php?scenario=$Scenario&slot=$Slot").GetAwaiter().GetResult()
    try {
        $taskBody = $taskResponse.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $taskWatch.Stop()
        $taskResult = [ordered]@{scenario=$Scenario;status=[int]$taskResponse.StatusCode;roundtrip_ms=$taskWatch.Elapsed.TotalMilliseconds;body=$taskBody}
        foreach ($taskPair in @(@('elapsed_ms','X-Phase0-Elapsed-Ms'),@('peak_bytes','X-Phase0-Peak-Bytes'),@('peak_allocated_bytes','X-Phase0-Peak-Allocated'))) {
            if ($taskResponse.Headers.Contains($taskPair[1])) {
                $taskResult[$taskPair[0]] = [double]::Parse(($taskResponse.Headers.GetValues($taskPair[1]) | Select-Object -First 1),[Globalization.CultureInfo]::InvariantCulture)
            }
        }
        if ($taskResponse.Headers.Contains('X-Phase0-Risk')) { $taskResult.risk = ($taskResponse.Headers.GetValues('X-Phase0-Risk') | Select-Object -First 1) }
        if ($taskResponse.Headers.Contains('X-Phase0-Degraded')) { $taskResult.degraded = ($taskResponse.Headers.GetValues('X-Phase0-Degraded') | Select-Object -First 1) }
        return [pscustomobject]$taskResult
    } finally { $taskResponse.Dispose() }
}

try {
    @'
<?php return array(
    'mode' => 'monitor',
    'identity' => array('trusted_proxies' => array('203.0.113.10')),
    'logging' => array('path' => getenv('PHASE0_BASE_DIR') . '/logs', 'hmac_key' => 'phase0-synthetic-key'),
    'viewer' => array('enabled' => true, 'allowed_ips' => array('198.51.100.1'))
);
'@ | Set-Content -LiteralPath (Join-Path $taskTemp 'guard.php') -Encoding utf8
    @'
<?php return array('storage' => array('path' => getenv('PHASE0_BASE_DIR') . '/state'));
'@ | Set-Content -LiteralPath (Join-Path $taskTemp 'engine.php') -Encoding utf8
    @'
<?php return array('storage' => array('path' => getenv('PHASE0_BASE_DIR') . '/blocked-file'));
'@ | Set-Content -LiteralPath (Join-Path $taskTemp 'engine-blocked.php') -Encoding utf8
    'synthetic file preventing state directory creation' | Set-Content -LiteralPath (Join-Path $taskTemp 'blocked-file')
    $taskListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
    $taskListener.Start()
    $taskPort = $taskListener.LocalEndpoint.Port
    $taskListener.Stop()
    $taskUrl = "http://127.0.0.1:$taskPort"
    $env:PHASE0_BASELINE = '1'
    $env:PHASE0_BASE_DIR = $taskTemp
    $taskPhp = (Get-Command $Php).Source
    # Temporary docroot exposes only fixtures plus a synthetic storage probe.
    $taskDocRoot = Join-Path $taskTemp 'www'
    $taskProbe = Join-Path $taskDocRoot 'storage'
    New-Item -ItemType Directory -Path $taskProbe | Out-Null
    $taskFixturePath = (Join-Path $taskRoot 'tests/fixtures/phase0-page.php').Replace('\','/').Replace("'","\'")
    ("<?php require '" + $taskFixturePath + "';") | Set-Content -LiteralPath (Join-Path $taskDocRoot 'phase0-page.php') -Encoding utf8
    Copy-Item -LiteralPath (Join-Path $taskRoot 'storage/.htaccess'),(Join-Path $taskRoot 'storage/web.config') -Destination $taskProbe
    '{"synthetic_probe":"phase0-public-storage"}' | Set-Content -LiteralPath (Join-Path $taskProbe 'probe.jsonl') -Encoding utf8
    $taskServer = Start-Process -FilePath $taskPhp -ArgumentList @('-d','opcache.enable_cli=0','-S',"127.0.0.1:$taskPort",'-t',('"'+$taskDocRoot+'"')) -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $taskTemp 'server-out.log') -RedirectStandardError (Join-Path $taskTemp 'server-error.log')
    $taskHandler = [Net.Http.HttpClientHandler]::new()
    $taskHandler.UseProxy = $false
    $taskHandler.UseCookies = $false
    $taskClient = [Net.Http.HttpClient]::new($taskHandler)
    $taskClient.Timeout = [TimeSpan]::FromSeconds(5)
    $taskClient.DefaultRequestHeaders.Add('User-Agent','Mozilla/5.0 Chrome/120.0 Safari/537.36')
    $taskClient.DefaultRequestHeaders.Add('Accept','text/html')
    $taskClient.DefaultRequestHeaders.Add('Accept-Language','ko-KR')
    $taskClient.DefaultRequestHeaders.Add('Accept-Encoding','gzip')
    $taskReady = $false
    for ($taskAttempt=0; $taskAttempt -lt 30; $taskAttempt++) {
        if ($taskServer.HasExited) { throw ('PHP server exited: ' + (Get-Content -LiteralPath (Join-Path $taskTemp 'server-error.log') -Raw)) }
        try { $taskPing=Invoke-Phase0Request 'minimal' 1; if ($taskPing.status -eq 200) { $taskReady=$true; break } } catch { Start-Sleep -Milliseconds 100 }
    }
    if (!$taskReady) { throw 'PHP server did not become ready' }
    $taskScenarios = @('minimal','guard-no-ads','plain-ads','guard-ads')
    $taskBodies = @{}
    foreach ($taskScenario in $taskScenarios) {
        for ($taskIndex=0; $taskIndex -lt $Warmup; $taskIndex++) {
            $taskRow=Invoke-Phase0Request $taskScenario (($taskIndex % 64)+1)
            if ($taskRow.status -ne 200) { throw "Warmup returned $($taskRow.status)" }
            $taskBodies[$taskScenario]=$taskRow.body
        }
    }
    if ($taskBodies.minimal -cne $taskBodies.'guard-no-ads' -or $taskBodies.'plain-ads' -cne $taskBodies.'guard-ads') { throw 'Guard changed synthetic normal page HTML' }
    $taskSamples=[System.Collections.Generic.List[object]]::new()
    # Rotate scenario order to reduce simple warm-cache/ordering bias.
    for ($taskIndex=0; $taskIndex -lt $Samples; $taskIndex++) {
        for ($taskOffset=0; $taskOffset -lt 4; $taskOffset++) {
            $taskScenario=$taskScenarios[($taskIndex+$taskOffset)%4]
            $taskRow=Invoke-Phase0Request $taskScenario ((($taskIndex+$Warmup)%64)+1)
            if ($taskRow.status -ne 200 -or $taskRow.body -cne $taskBodies[$taskScenario]) { throw "Response regression: $taskScenario" }
            if ($taskScenario -eq 'guard-ads' -and ($taskRow.risk -ne 'NORMAL' -or $taskRow.degraded -ne '0')) { throw 'Normal scenario became risky or degraded' }
            $taskRow.PSObject.Properties.Remove('body')
            $taskSamples.Add($taskRow)
        }
    }
    $taskSummary=[ordered]@{}
    foreach ($taskScenario in $taskScenarios) {
        $taskRows=@($taskSamples | Where-Object scenario -eq $taskScenario)
        $taskSummary[$taskScenario]=[ordered]@{roundtrip_ms=(Get-Phase0Stats $taskRows.roundtrip_ms);php_body_ms=(Get-Phase0Stats $taskRows.elapsed_ms);php_peak_bytes=(Get-Phase0Stats $taskRows.peak_bytes);php_peak_allocated_bytes=(Get-Phase0Stats $taskRows.peak_allocated_bytes)}
        Write-Output "$taskScenario PHP p50=$($taskSummary[$taskScenario].php_body_ms.p50) ms p95=$($taskSummary[$taskScenario].php_body_ms.p95) ms"
    }
    $taskFailure=Invoke-Phase0Request 'guard-write-failure' 1
    $taskViewer=Invoke-Phase0Request 'viewer-proxy' 1
    $taskProbeResponse=$taskClient.GetAsync("$taskUrl/storage/probe.jsonl").GetAwaiter().GetResult()
    try {
        $taskProbeBody=$taskProbeResponse.Content.ReadAsStringAsync().GetAwaiter().GetResult()
        $taskProbeResult=[ordered]@{status=[int]$taskProbeResponse.StatusCode;synthetic_marker_visible=$taskProbeBody.Contains('phase0-public-storage');server='PHP built-in dev server only; not evidence of Apache/IIS/nginx production configuration'}
    } finally { $taskProbeResponse.Dispose() }
    $taskResults=[ordered]@{
        generated_at_utc=[DateTime]::UtcNow.ToString('o')
        php_version=(& $taskPhp '-v' | Out-String).Trim()
        environment='Windows; PHP built-in single worker; loopback; opcache.enable_cli=0; default monitor policy/rate thresholds/GC; 64 synthetic direct clients with persistent cookies; all synthetic data; no remote HTTP'
        samples_per_scenario=$Samples
        warmup_per_scenario=$Warmup
        html_unchanged=$true
        normal_guard_requests=$Samples
        summary=$taskSummary
        failure_probe=[ordered]@{status=$taskFailure.status;body_unchanged=($taskFailure.body -ceq $taskBodies.'plain-ads');degraded=$taskFailure.degraded}
        viewer_proxy_probe=[ordered]@{status=$taskViewer.status;peer='203.0.113.10';forwarded_client='198.51.100.1';allowed_ip='198.51.100.1'}
        storage_webroot_probe=$taskProbeResult
        samples=$taskSamples.ToArray()
    }
    $taskOutputDir=Split-Path $OutputPath -Parent
    if (!(Test-Path -LiteralPath $taskOutputDir)) { New-Item -ItemType Directory -Path $taskOutputDir | Out-Null }
    $taskResults | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $OutputPath -Encoding utf8
    if ($taskFailure.status -ne 200 -or $taskFailure.body -cne $taskBodies.'plain-ads' -or $taskFailure.degraded -ne '1') { throw 'Write failure did not preserve monitor response' }
    if ($taskViewer.status -ne 404) { throw 'Viewer peer-based v1 behavior changed' }
    Write-Output 'Response equality, normal risk, storage failure, viewer proxy probes: PASS'
    Write-Output "Synthetic webroot storage probe: HTTP $($taskProbeResult.status), visible=$($taskProbeResult.synthetic_marker_visible)"
} finally {
    if ($null -ne $taskClient) { $taskClient.Dispose() }
    if ($null -ne $taskServer -and !$taskServer.HasExited) { Stop-Process -Id $taskServer.Id -Force }
    $env:PHASE0_BASELINE=$taskOldFlag
    $env:PHASE0_BASE_DIR=$taskOldBase
    $taskResolved=[IO.Path]::GetFullPath($taskTemp)
    $taskTempRoot=[IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\','/') + [IO.Path]::DirectorySeparatorChar
    if (!$taskResolved.StartsWith($taskTempRoot,[StringComparison]::OrdinalIgnoreCase) -or !(Split-Path $taskResolved -Leaf).StartsWith('adguard-phase0-http-')) { throw 'Refusing cleanup outside task temporary directory' }
    if (Test-Path -LiteralPath $taskResolved) { Remove-Item -LiteralPath $taskResolved -Recurse -Force }
}
