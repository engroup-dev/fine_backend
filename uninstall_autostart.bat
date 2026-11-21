@echo off
setlocal
title Fine Backend - Remove Autostart and Shortcuts
cd /d "%~dp0"

echo ========================================================
echo        Fine Backend - Remove Autostart and Shortcuts
echo ========================================================
echo.

:: Each quoted chunk must close its quotes before the ^ or cmd ends the command there.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$desktop = [Environment]::GetFolderPath('Desktop');" ^
  "$startup = [Environment]::GetFolderPath('Startup');" ^
  "$links = @((Join-Path $desktop 'Start Fine Backend.lnk'), (Join-Path $desktop 'Stop Fine Backend.lnk'), (Join-Path $startup 'Fine_Backend_Autostart.lnk'));" ^
  "foreach ($f in $links) { if (Test-Path -LiteralPath $f) { Remove-Item -LiteralPath $f -Force; Write-Host '[REMOVED]' $f } }"

schtasks /Delete /TN "Fine Backend Scheduler" /F >nul 2>&1
if not errorlevel 1 echo [REMOVED] Scheduled jobs task "Fine Backend Scheduler"

echo.
echo Autostart, shortcuts and the scheduled jobs task removed.
echo The backend itself is still running if it was started; use stop_backend.bat to stop it.
pause
