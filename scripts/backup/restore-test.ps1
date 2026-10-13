<#
.SYNOPSIS
    Uji restore backup EduCore ke database SEMENTARA di Windows/Laragon (PRD-000 §9).

.DESCRIPTION
    Membuat database educore_restore_test, me-restore file backup (terbaru atau -DumpFile),
    menghitung isi beberapa tabel penting, lalu menghapus database uji. Database asli tidak disentuh.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup\restore-test.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup\restore-test.ps1 -DumpFile D:\backup\educore\educore_20261013_013000.dump
#>
param(
    [string]$AppDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$BackupDir = (Join-Path $env:USERPROFILE 'educore-backup'),
    [string]$DumpFile = '',
    [string]$PgBin = ''
)

$ErrorActionPreference = 'Stop'
$testDb = 'educore_restore_test'

function Write-Log([string]$Message) {
    Write-Host ("[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message)
}

function Get-EnvValue([string]$File, [string]$Key) {
    $line = Get-Content $File | Where-Object { $_ -match "^$Key=" } | Select-Object -Last 1
    if (-not $line) { return '' }
    $value = $line.Substring($Key.Length + 1).Trim()
    if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
        $value = $value.Substring(1, $value.Length - 2)
    }
    return $value
}

function Resolve-PgTool([string]$Name) {
    if ($PgBin -ne '') {
        $path = Join-Path $PgBin "$Name.exe"
        if (-not (Test-Path $path)) { throw "$Name.exe tidak ditemukan di $PgBin" }
        return $path
    }
    $command = Get-Command $Name -ErrorAction SilentlyContinue
    if (-not $command) { throw "$Name tidak ditemukan di PATH. Isi parameter -PgBin dengan folder bin PostgreSQL." }
    return $command.Source
}

$envFile = Join-Path $AppDir '.env'
if (-not (Test-Path $envFile)) { throw "File .env tidak ditemukan di $AppDir" }

$psql = Resolve-PgTool 'psql'
$pgRestore = Resolve-PgTool 'pg_restore'

$dbHost = Get-EnvValue $envFile 'DB_HOST'; if ($dbHost -eq '') { $dbHost = '127.0.0.1' }
$dbPort = Get-EnvValue $envFile 'DB_PORT'; if ($dbPort -eq '') { $dbPort = '5432' }
$dbUser = Get-EnvValue $envFile 'DB_USERNAME'; if ($dbUser -eq '') { $dbUser = 'postgres' }
if ((Get-EnvValue $envFile 'DB_DATABASE') -eq $testDb) { throw 'DB_DATABASE tidak boleh sama dengan database uji' }

if ($DumpFile -eq '') {
    $latest = Get-ChildItem $BackupDir -Filter 'educore_*.dump' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $latest) { throw "File backup tidak ditemukan di $BackupDir" }
    $DumpFile = $latest.FullName
}
if (-not (Test-Path $DumpFile)) { throw "File backup tidak ditemukan: $DumpFile" }

$env:PGPASSWORD = Get-EnvValue $envFile 'DB_PASSWORD'
# NOTICE (mis. dari DROP DATABASE IF EXISTS) tidak perlu tampil; bisa dianggap error oleh PowerShell 5.1.
$env:PGOPTIONS = '-c client_min_messages=warning'
$connection = @("--host=$dbHost", "--port=$dbPort", "--username=$dbUser", '--no-password')

function Invoke-Admin([string]$Sql) {
    & $psql -X @connection --dbname=postgres --quiet --command=$Sql | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "Perintah gagal: $Sql" }
}

function Get-Count([string]$Table) {
    $result = & $psql -X @connection "--dbname=$testDb" --tuples-only --no-align "--command=SELECT count(*) FROM $Table"
    if ($LASTEXITCODE -ne 0) { throw "Gagal menghitung $Table" }
    return [int]($result | Select-Object -First 1)
}

try {
    Write-Log "Uji restore dari $DumpFile"
    Invoke-Admin "DROP DATABASE IF EXISTS $testDb"
    Invoke-Admin "CREATE DATABASE $testDb"

    $watch = [System.Diagnostics.Stopwatch]::StartNew()
    & $pgRestore @connection --no-owner --no-privileges --exit-on-error "--dbname=$testDb" $DumpFile
    if ($LASTEXITCODE -ne 0) { throw "pg_restore gagal (kode $LASTEXITCODE)" }
    $watch.Stop()

    $tenants = Get-Count 'tenants'
    $organizations = Get-Count 'organizations'
    $users = Get-Count 'users'
    $audit = Get-Count 'audit_logs'

    if ($tenants -le 0) { throw 'Hasil restore tidak berisi yayasan' }

    Write-Log ("BERHASIL dalam {0} detik: tenants={1}, organizations={2}, users={3}, audit_logs={4}" -f [math]::Round($watch.Elapsed.TotalSeconds), $tenants, $organizations, $users, $audit)
}
finally {
    & $psql -X @connection --dbname=postgres --quiet "--command=DROP DATABASE IF EXISTS $testDb" | Out-Null
    Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:\PGOPTIONS -ErrorAction SilentlyContinue
    Write-Log "Database uji $testDb dihapus kembali."
}
