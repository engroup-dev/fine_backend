@echo off
setlocal
title Fine Backend - Remove Autostart and Shortcuts
cd /d "%~dp0"

echo ========================================================
echo        Fine Backend - Remove Autostart and Shortcuts
echo ========================================================
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$desktop = [Environment]::GetFolderPath('Desktop'); ^
   $startup = [Environment]::GetFolderPath('Startup'); ^
   $f1 = Join-Path $desktop 'Start Fine Backend.lnk'; ^
   $f2 = Join-Path $desktop 'Stop Fine Backend.lnk'; ^
   $f3 = Join-Path $startup 'Fine_Backend_Autostart.lnk'; ^
   if (Test-Path $f1) { Remove-Item $f1 -Force; Write-Host '[REMOVED]' $f1; }; ^
   if (Test-Path $f2) { Remove-Item $f2 -Force; Write-Host '[REMOVED]' $f2; }; ^
   if (Test-Path $f3) { Remove-Item $f3 -Force; Write-Host '[REMOVED]' $f3; };"

echo.
echo Autostart and shortcuts removed.
pause
