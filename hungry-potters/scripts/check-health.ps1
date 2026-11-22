[CmdletBinding()]
param(
    [string]$XamppPath = 'C:\xampp_jaka',
    [string]$ProjectPath = (Split-Path $PSScriptRoot -Parent),
    [string]$BaseUrl = 'http://localhost/hungry-potters'
)

$ErrorActionPreference = 'Stop'
$script:Failures = 0
$mysql = Join-Path $XamppPath 'mysql\bin\mysql.exe'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Report-Check([string]$Name, [bool]$Passed, [string]$Fix) {
    if ($Passed) {
        Write-Host "PASS: $Name"
    } else {
        $script:Failures += 1
        Write-Host "FAIL: $Name - Fix: $Fix"
    }
}

function Invoke-RootQuery([string]$Sql) {
    if (-not (Test-Path $mysql)) { return $null }
    $output = & $mysql --protocol=tcp --host=127.0.0.1 --port=3306 --user=root --batch --skip-column-names --execute=$Sql 2>$null
    if ($LASTEXITCODE -ne 0) { return $null }
    return @($output)
}

function Get-HttpStatus([string]$Url) {
    try {
        $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 6
        return [int]$response.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        return 0
    }
}

function Get-IniValue([string[]]$Lines, [string]$Section, [string]$Key) {
    $inside = $false
    foreach ($line in $Lines) {
        if ($line -match '^\s*\[([^]]+)\]') {
            $inside = $Matches[1] -eq $Section
            continue
        }
        if ($inside -and $line -match "^\s*$Key\s*=\s*(.*?)\s*$") {
            return $Matches[1]
        }
    }
    return $null
}

$mysqlListening = $false
try {
    $client = [System.Net.Sockets.TcpClient]::new()
    $pending = $client.BeginConnect('127.0.0.1', 3306, $null, $null)
    if ($pending.AsyncWaitHandle.WaitOne(1200) -and $client.Connected) {
        $client.EndConnect($pending)
        $mysqlListening = $true
    }
    $client.Close()
} catch { }
Report-Check 'MySQL is listening on port 3306' $mysqlListening 'Start MySQL from the XAMPP Control Panel.'

$mysql80 = Get-CimInstance Win32_Service -Filter "Name='MySQL80'" -ErrorAction SilentlyContinue
$mysql80Safe = (-not $mysql80) -or ($mysql80.StartMode -eq 'Disabled')
Report-Check 'MySQL80 cannot auto-start and conflict with XAMPP' $mysql80Safe 'After confirming it is not the XAMPP database, run Set-Service MySQL80 -StartupType Disabled as Administrator.'

$dbSummary = Invoke-RootQuery "SELECT CONCAT((SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='hungry_potters'), ',', (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='hungry_potters'));"
$dbParts = if ($dbSummary) { [string](@($dbSummary)[-1]) -split ',' } else { @() }
$dbExists = $dbParts.Count -eq 2 -and $dbParts[0] -eq '1'
$dbHasTables = $dbExists -and [int]$dbParts[1] -gt 0
Report-Check 'hungry_potters exists and has tables' $dbHasTables 'Start MySQL, then import database/database.sql or a verified database backup.'

$grants = Invoke-RootQuery 'SHOW GRANTS FOR ''hungry_potters_app''@''localhost'';'
$grantLines = @($grants | ForEach-Object { ([string]$_).Replace([string][char]96, '').Replace([string][char]39, '') } | Where-Object { $_ -match '^GRANT ' })
$schemaGrant = @($grantLines | Where-Object { $_ -match '^GRANT (.+?) ON hungry_potters\.\* TO hungry_potters_app@localhost' })
$usageGrant = @($grantLines | Where-Object { $_ -match '^GRANT USAGE ON \*\.\* TO hungry_potters_app@localhost' })
$exactGrants = $false
if ($grantLines.Count -eq 2 -and $schemaGrant.Count -eq 1 -and $usageGrant.Count -eq 1 -and $schemaGrant[0] -notmatch 'WITH GRANT OPTION') {
    $privilegeText = [regex]::Match($schemaGrant[0], '^GRANT (.+?) ON ').Groups[1].Value
    $actual = @($privilegeText -split ',\s*' | Sort-Object)
    $expected = @('DELETE', 'INSERT', 'SELECT', 'UPDATE')
    $exactGrants = ($actual -join ',') -ceq ($expected -join ',')
}
Report-Check 'hungry_potters_app has exactly the four required database grants' $exactGrants 'Run GRANT SELECT, INSERT, UPDATE, DELETE ON hungry_potters.* TO ''hungry_potters_app''@''localhost''; as MySQL root, then remove any extra grants.'

$apacheRunning = [bool](Get-Process -Name httpd -ErrorAction SilentlyContinue)
Report-Check 'Apache is running' $apacheRunning 'Start Apache in the XAMPP Control Panel.'

$temporaryPhp = Join-Path $ProjectPath ('.health-env-' + [guid]::NewGuid().ToString('N') + '.php')
$envNames = @('HP_DB_HOST', 'HP_DB_NAME', 'HP_DB_USER', 'HP_DB_PASSWORD')
$envStatus = $null
try {
    $php = @'
<?php
header("Content-Type: application/json");
$names = ["HP_DB_HOST", "HP_DB_NAME", "HP_DB_USER", "HP_DB_PASSWORD", "HP_DB_PORT"];
$status = [];
foreach ($names as $name) {
    $value = getenv($name);
    $status[$name] = $value !== false && $value !== "";
}
echo json_encode($status);
'@
    Set-Content -LiteralPath $temporaryPhp -Value $php -Encoding ASCII
    $status = Get-HttpStatus ($BaseUrl + '/' + (Split-Path $temporaryPhp -Leaf))
    if ($status -eq 200) {
        $envStatus = Invoke-RestMethod -Uri ($BaseUrl + '/' + (Split-Path $temporaryPhp -Leaf)) -TimeoutSec 6
    }
} catch { } finally {
    Remove-Item -LiteralPath $temporaryPhp -Force -ErrorAction SilentlyContinue
}
foreach ($name in $envNames) {
    $isSet = $null -ne $envStatus -and [bool]$envStatus.$name
    Report-Check "$name reaches PHP through Apache" $isSet 'Check the external Apache SetEnv configuration, Include line, and restart Apache.'
}
$portStatus = if ($null -eq $envStatus) {
    'Could not read Apache environment status.'
} elseif ([bool]$envStatus.HP_DB_PORT) {
    'HP_DB_PORT is set through Apache.'
} else {
    'HP_DB_PORT is unset; the application defaults to 3306.'
}
Write-Host "INFO: $portStatus"

$loginStatus = Get-HttpStatus ($BaseUrl + '/login.php')
Report-Check 'login.php returns HTTP 200' ($loginStatus -eq 200) 'Start Apache and MySQL and correct the external HP_DB_* settings and database grants.'
$queueStatus = Get-HttpStatus ($BaseUrl + '/api/queue-status.php')
Report-Check 'api/queue-status.php returns HTTP 200' ($queueStatus -eq 200) 'Start Apache and MySQL and confirm the application database is accessible.'
$databaseStatus = Get-HttpStatus ($BaseUrl + '/database/database.sql')
Report-Check 'database/database.sql is blocked with HTTP 403' ($databaseStatus -eq 403) 'Restore the external Apache <Directory .../database> Require all denied rule and restart Apache.'

$controlIni = Join-Path $XamppPath 'xampp-control.ini'
$iniLines = if (Test-Path $controlIni) { Get-Content $controlIni } else { @() }
$mysqlAuto = (Get-IniValue $iniLines 'Autostart' 'MySQL') -eq '1'
$apacheAuto = (Get-IniValue $iniLines 'Autostart' 'Apache') -eq '1'
Report-Check 'XAMPP MySQL autostart is enabled' $mysqlAuto 'Set MySQL=1 under [Autostart] in xampp-control.ini as Administrator, then reopen XAMPP Control Panel.'
Report-Check 'XAMPP Apache autostart is enabled' $apacheAuto 'Set Apache=1 under [Autostart] in xampp-control.ini as Administrator, then reopen XAMPP Control Panel.'

if ($script:Failures -gt 0) {
    Write-Host "Health check finished with $($script:Failures) failure(s)."
    exit 1
}
Write-Host 'Health check passed.'
