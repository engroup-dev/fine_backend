@echo off
setlocal
title Stop Fine Backend
set "FINE_PORT=8000"
echo Stopping Fine Backend on port %FINE_PORT%...
echo.

:: Only PHP processes are killed, so an unrelated app on the same port is left alone.
:: Each quoted chunk must close its quotes before the ^ or cmd ends the command there.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$port = [int]$env:FINE_PORT;" ^
  "$ids = @(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique);" ^
  "if ($ids.Count -eq 0) { Write-Host ('[INFO] No active process was found listening on port {0}.' -f $port); exit 0 };" ^
  "$failed = 0;" ^
  "foreach ($id in $ids) {" ^
  "  $p = Get-Process -Id $id -ErrorAction SilentlyContinue;" ^
  "  if (-not $p) { continue };" ^
  "  if ($p.ProcessName -ne 'php') { Write-Host ('[SKIP] Port {0} is used by {1} (PID {2}), not PHP. Leaving it alone.' -f $port, $p.ProcessName, $id); $failed++; continue };" ^
  "  Write-Host ('Terminating process PID {0}...' -f $id);" ^
  "  try { Stop-Process -Id $id -Force -ErrorAction Stop } catch { Write-Host ('[ERROR] Could not stop PID {0}: {1}' -f $id, $_.Exception.Message); $failed++ }" ^
  "};" ^
  "if ($failed -eq 0) { Write-Host '[OK] Backend stopped successfully.' };" ^
  "exit $failed"

timeout /t 3 >nul
