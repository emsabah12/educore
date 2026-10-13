<#
.SYNOPSIS
    Backup database EduCore di Windows/Laragon (PRD-000 §9). Untuk development atau server Windows.

.DESCRIPTION
    Membaca kredensial dari .env aplikasi, menjalankan pg_dump (format custom), memeriksa
    file hasilnya dengan pg_restore --list, lalu menghapus backup yang lebih tua dari
    -RetentionDays hari. Password tidak pernah ditampilkan.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup\backup-db.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File scripts\backup\backup-db.ps1 -BackupDir D:\backup\educore -PgBin "C:\laragon\bin\postgresql\postgresql-16.4\bin"
#>
param(
    [string]$AppDir = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string]$BackupDir = (Join-Path $env:USERPROFILE 'educore-backup'),
    [int]$RetentionDays = 14,
    # Folder bin PostgreSQL bila pg_dump belum ada di PATH (mis. folder bin PostgreSQL di Laragon).
    [string]$PgBin = ''
)

$ErrorActionPreference = 'Stop'

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

$pgDump = Resolve-PgTool 'pg_dump'
$pgRestore = Resolve-PgTool 'pg_restore'

$dbHost = Get-EnvValue $envFile 'DB_HOST'; if ($dbHost -eq '') { $dbHost = '127.0.0.1' }
$dbPort = Get-EnvValue $envFile 'DB_PORT'; if ($dbPort -eq '') { $dbPort = '5432' }
$dbName = Get-EnvValue $envFile 'DB_DATABASE'
$dbUser = Get-EnvValue $envFile 'DB_USERNAME'; if ($dbUser -eq '') { $dbUser = 'postgres' }
if ($dbName -eq '') { throw 'DB_DATABASE kosong di .env' }

New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null

$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$target = Join-Path $BackupDir "educore_$stamp.dump"
$partial = "$target.partial"

$env:PGPASSWORD = Get-EnvValue $envFile 'DB_PASSWORD'

try {
    Write-Log "Mulai backup database $dbName"

    & $pgDump --host=$dbHost --port=$dbPort --username=$dbUser --no-password --format=custom --no-owner --no-privileges "--file=$partial" $dbName
    if ($LASTEXITCODE -ne 0) { throw "pg_dump gagal (kode $LASTEXITCODE)" }

    & $pgRestore --list $partial | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "File backup rusak: $partial" }

    Move-Item -Force $partial $target
    $sizeKb = [math]::Round((Get-Item $target).Length / 1KB)
    Write-Log "Backup selesai: $target ($sizeKb KB)"

    $old = Get-ChildItem $BackupDir -Filter 'educore_*.dump' | Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-$RetentionDays) }
    if ($old) { $old | Remove-Item -Force }
    Write-Log ("Rotasi: {0} file lebih tua dari {1} hari dihapus" -f @($old).Count, $RetentionDays)
}
finally {
    Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
    if (Test-Path $partial) { Remove-Item -Force $partial }
}
