param(
    [string]$Php = 'C:/php-8.5.5/php.exe'
)

$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
$taskBase = Join-Path ([IO.Path]::GetTempPath()) ('adguard-phase1-http-' + [guid]::NewGuid().ToString('N'))
$taskProcess = $null

try {
    $null = New-Item -ItemType Directory -Path $taskBase
    $taskBlocked = Join-Path $taskBase 'blocked-log-parent'
    [IO.File]::WriteAllText($taskBlocked, 'file prevents directory provisioning')
    $taskGuard = Join-Path $taskBase 'guard.php'
    $taskRisk = Join-Path $taskBase 'engine.php'
    $taskLogPath = ($taskBlocked + '/logs').Replace('\', '/')
    $taskStatePath = (Join-Path $taskBase 'state').Replace('\', '/')
    [IO.File]::WriteAllText($taskGuard, @"
<?php return array(
    'mode' => 'monitor',
    'logging' => array('enabled' => true, 'path' => '$taskLogPath', 'hmac_key' => 'phase1-http-key'),
);
"@)
    [IO.File]::WriteAllText($taskRisk, @"
<?php return array(
    'storage' => array('path' => '$taskStatePath', 'gc_probability' => 0),
);
"@)

    $taskListener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $taskListener.Start()
    $taskPort = ([Net.IPEndPoint]$taskListener.LocalEndpoint).Port
    $taskListener.Stop()

    $taskStart = [Diagnostics.ProcessStartInfo]::new()
    $taskStart.FileName = (Get-Command $Php).Source
    $taskStart.UseShellExecute = $false
    $taskStart.CreateNoWindow = $true
    $taskStart.RedirectStandardOutput = $true
    $taskStart.RedirectStandardError = $true
    $taskStart.ArgumentList.Add('-S')
    $taskStart.ArgumentList.Add("127.0.0.1:$taskPort")
    $taskStart.ArgumentList.Add('-t')
    $taskStart.ArgumentList.Add($taskRoot)
    $taskStart.Environment['ADGUARD_PHASE1_FIXTURE'] = '1'
    $taskStart.Environment['AD_GUARD_CONFIG'] = $taskGuard
    $taskStart.Environment['RISK_ENGINE_CONFIG'] = $taskRisk

    $taskProcess = [Diagnostics.Process]::new()
    $taskProcess.StartInfo = $taskStart
    $null = $taskProcess.Start()
    $taskResponse = $null
    for ($taskAttempt = 0; $taskAttempt -lt 40; $taskAttempt++) {
        try {
            $taskResponse = Invoke-WebRequest -UseBasicParsing -TimeoutSec 1 -Uri "http://127.0.0.1:$taskPort/tests/fixtures/telemetry-page.php"
            break
        } catch {
            Start-Sleep -Milliseconds 50
        }
    }
    if ($null -eq $taskResponse) {
        throw 'loopback fixture did not answer'
    }
    if ($taskResponse.StatusCode -ne 200) {
        throw "expected HTTP 200, received $($taskResponse.StatusCode)"
    }
    if ($taskResponse.Content -ne 'PHASE1_HTTP_CONTENT') {
        throw 'storage failure changed the application response body'
    }
    Write-Output 'PASS storage failure remains HTTP 200 with the original response body'
} finally {
    if ($null -ne $taskProcess) {
        if (!$taskProcess.HasExited) {
            try { $taskProcess.Kill($true) } catch { $taskProcess.Kill() }
            $taskProcess.WaitForExit()
        }
        $taskProcess.Dispose()
    }
    $taskResolved = if (Test-Path -LiteralPath $taskBase) { (Resolve-Path -LiteralPath $taskBase).Path } else { '' }
    $taskTemp = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
    if ($taskResolved -ne '' -and $taskResolved.StartsWith($taskTemp + '\', [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $taskResolved -Recurse -Force
    }
}
