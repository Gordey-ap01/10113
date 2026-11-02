# Gordey_ap01 — stop containers; retain database and all restored files.
$ErrorActionPreference = 'Stop'
$recoveryRoot = Split-Path -Parent (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
docker compose --env-file (Join-Path $recoveryRoot 'runtime\secrets.env') -p service101-recovery-20261102 -f (Join-Path $PSScriptRoot 'compose.yaml') stop
if ($LASTEXITCODE -ne 0) { throw 'Docker stop failed.' }
