param(
    [string]$OutputPath = '',
    [string]$Php = 'php'
)
$ErrorActionPreference = 'Stop'
$taskRoot = Split-Path $PSScriptRoot -Parent
if ($OutputPath -eq '') { $OutputPath = Join-Path $taskRoot 'docs/adguard/phase0/checks.json' }
$taskResults = [System.Collections.Generic.List[object]]::new()
$taskPhpPath = (Get-Command $Php).Source
function Invoke-Phase0Php([string[]]$Arguments) {
    $taskStart = [Diagnostics.ProcessStartInfo]::new()
    $taskStart.FileName = $taskPhpPath
    $taskStart.UseShellExecute = $false
    $taskStart.CreateNoWindow = $true
    $taskStart.RedirectStandardOutput = $true
    $taskStart.RedirectStandardError = $true
    foreach ($taskArgument in $Arguments) { $taskStart.ArgumentList.Add($taskArgument) }
    $taskProcess = [Diagnostics.Process]::new()
    $taskProcess.StartInfo = $taskStart
    try {
        $null = $taskProcess.Start()
        $taskOut = $taskProcess.StandardOutput.ReadToEndAsync()
        $taskErr = $taskProcess.StandardError.ReadToEndAsync()
        $taskTimedOut = !$taskProcess.WaitForExit(30000)
        if ($taskTimedOut) { $taskProcess.Kill($true); $taskProcess.WaitForExit() }
        $taskOutput = $taskOut.GetAwaiter().GetResult() + $taskErr.GetAwaiter().GetResult()
        if ($taskTimedOut) { $taskOutput += "`nTIMEOUT after 30 seconds; stopped test process tree.`n" }
        return [pscustomobject]@{output=$taskOutput;exit_code=$(if ($taskTimedOut) { 124 } else { $taskProcess.ExitCode })}
    } finally { $taskProcess.Dispose() }
}
Push-Location $taskRoot
try {
    $taskTests = @(Get-ChildItem -LiteralPath 'tests','engine/tests' -Filter '*-test.php' -File | Sort-Object FullName)
    foreach ($taskTest in $taskTests) {
        $taskRelative = $taskTest.FullName.Substring($taskRoot.Length + 1).Replace('\','/')
        $taskWatch = [Diagnostics.Stopwatch]::StartNew()
        $taskRun = Invoke-Phase0Php @($taskTest.FullName)
        $taskOutput = $taskRun.output
        $taskCode = $taskRun.exit_code
        $taskWatch.Stop()
        $taskStatus = if ($taskCode -ne 0) { 'FAIL' } elseif ($taskOutput -match '(?im)^\s*SKIP\b') { 'SKIP' } else { 'PASS' }
        $taskResults.Add([pscustomobject]@{name=$taskRelative;command="php $taskRelative";status=$taskStatus;exit_code=$taskCode;duration_ms=$taskWatch.Elapsed.TotalMilliseconds;output=$taskOutput})
        Write-Output "$taskStatus $taskRelative"
    }
    foreach ($taskSpecial in @('engine/tests/boundary-check.php','tools/php56-check-selftest.php')) {
        $taskRun = Invoke-Phase0Php @($taskSpecial)
        $taskOutput = $taskRun.output
        $taskCode = $taskRun.exit_code
        $taskStatus = if ($taskCode -eq 0) { 'PASS' } else { 'FAIL' }
        $taskResults.Add([pscustomobject]@{name=$taskSpecial;command="php $taskSpecial";status=$taskStatus;exit_code=$taskCode;output=$taskOutput})
        Write-Output "$taskStatus $taskSpecial"
    }
    # The intentionally post-5.6 fixture and its checker are development tools.
    $taskSyntaxTargets = @('adguard.php','auto-prepend.php','viewer.php','config','src','engine','tests')
    $taskSyntaxTargets += @(Get-ChildItem -LiteralPath 'tools' -File -Filter '*.php' | Where-Object { $_.Name -notin @('php56-check.php','php56-check-selftest.php') } | ForEach-Object { 'tools/' + $_.Name })
    $taskRun = Invoke-Phase0Php (@('tools/php56-check.php') + $taskSyntaxTargets)
    $taskOutput = $taskRun.output
    $taskCode = $taskRun.exit_code
    $taskStatus = if ($taskCode -eq 0) { 'PASS' } else { 'FAIL' }
    $taskResults.Add([pscustomobject]@{name='php56-static';command=('php tools/php56-check.php ' + ($taskSyntaxTargets -join ' '));status=$taskStatus;exit_code=$taskCode;output=$taskOutput})
    Write-Output "$taskStatus php56-static"
    $taskLint = @()
    foreach ($taskFile in @(Get-ChildItem -LiteralPath $taskRoot -Recurse -File -Filter '*.php' | Where-Object { $_.FullName -notmatch '[\\/]\.git[\\/]' })) {
        $taskRun = Invoke-Phase0Php @('-l',$taskFile.FullName)
        $taskOutput = $taskRun.output
        $taskCode = $taskRun.exit_code
        $taskLint += [pscustomobject]@{path=$taskFile.FullName.Substring($taskRoot.Length+1).Replace('\','/');exit_code=$taskCode;output=$taskOutput}
    }
    $taskStatus = if (@($taskLint | Where-Object exit_code -ne 0).Count -eq 0) { 'PASS' } else { 'FAIL' }
    $taskResults.Add([pscustomobject]@{name='php-lint';command='php -l <each PHP file>';status=$taskStatus;files=$taskLint})
    Write-Output "$taskStatus php-lint ($($taskLint.Count) files)"
    $taskSummary = [ordered]@{
        generated_at_utc=[DateTime]::UtcNow.ToString('o')
        php_version=(& $Php '-v' | Out-String).Trim()
        pass=@($taskResults | Where-Object status -eq 'PASS').Count
        fail=@($taskResults | Where-Object status -eq 'FAIL').Count
        skip=@($taskResults | Where-Object status -eq 'SKIP').Count
        checks=$taskResults.ToArray()
    }
    $taskOutputDirectory = Split-Path $OutputPath -Parent
    if (!(Test-Path -LiteralPath $taskOutputDirectory)) { New-Item -ItemType Directory -Path $taskOutputDirectory | Out-Null }
    $taskSummary | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $OutputPath -Encoding utf8
    Write-Output "PASS=$($taskSummary.pass) FAIL=$($taskSummary.fail) SKIP=$($taskSummary.skip)"
    if ($taskSummary.fail -gt 0) { exit 1 }
} finally {
    Pop-Location
}
