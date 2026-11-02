# Gordey_ap01 — start existing local copies without importing over later edits.
$ErrorActionPreference = 'Stop'
$recoveryRoot = Split-Path -Parent (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envFile = Join-Path $recoveryRoot 'runtime\secrets.env'
if (-not (Test-Path -LiteralPath (Join-Path $recoveryRoot 'runtime\restored.json'))) {
    throw 'Initial database restore is not complete. See the recovery report.'
}
docker compose --env-file $envFile -p service101-recovery-20261102 -f (Join-Path $PSScriptRoot 'compose.yaml') up -d
if ($LASTEXITCODE -ne 0) { throw 'Docker startup failed.' }
Write-Output 'Боевой сайт: http://127.0.0.1:8184/'
Write-Output 'WordPress: http://127.0.0.1:8185/'
Write-Output 'Gordey_ap01'
