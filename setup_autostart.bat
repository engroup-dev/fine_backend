@echo off
setlocal enabledelayedexpansion
title Fine Backend - Setup Autostart and Desktop Shortcut
cd /d "%~dp0"

echo ========================================================
echo         Fine Backend - Setup Autostart ^& Shortcut
echo ========================================================
echo.

if exist "%~dp0artisan" (
    set "BACKEND_DIR=%~dp0"
    set "ICON_PATH=%~dp0public\favicon.ico"
) else if exist "%~dp0fine_backend\artisan" (
    set "BACKEND_DIR=%~dp0fine_backend\"
    set "ICON_PATH=%~dp0fine_backend\public\favicon.ico"
) else (
    echo [ERROR] Cannot locate backend directory.
    pause
    exit /b 1
)

echo Target Host : 192.168.2.200
echo Target Port : 8000
echo Directory   : !BACKEND_DIR!
echo.

:: Check PHP in PATH
where php >nul 2>&1
if %ERRORLEVEL% neq 0 (
    echo [WARNING] 'php' command was not found in your system PATH.
    echo Please make sure PHP is installed and in your environment PATH.
    echo.
) else (
    echo [OK] PHP executable detected.
)

echo Creating Desktop shortcuts and Windows Startup entry...
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ws = New-Object -ComObject WScript.Shell; ^
   $desktop = [Environment]::GetFolderPath('Desktop'); ^
   $startup = [Environment]::GetFolderPath('Startup'); ^
   $projDir = '%~dp0'.TrimEnd('\'); ^
   $vbsPath = Join-Path $projDir 'start_backend_hidden.vbs'; ^
   $stopPath = Join-Path $projDir 'stop_backend.bat'; ^
   $iconPath = '!ICON_PATH!'; ^
   ^
   $s1 = $ws.CreateShortcut((Join-Path $desktop 'Start Fine Backend.lnk')); ^
   $s1.TargetPath = 'wscript.exe'; ^
   $s1.Arguments = '\"' + $vbsPath + '\"'; ^
   $s1.WorkingDirectory = $projDir; ^
   if (Test-Path $iconPath) { $s1.IconLocation = $iconPath; }; ^
   $s1.Description = 'Start Fine Backend on 192.168.2.200:8000 in background'; ^
   $s1.Save(); ^
   ^
   $s2 = $ws.CreateShortcut((Join-Path $desktop 'Stop Fine Backend.lnk')); ^
   $s2.TargetPath = $stopPath; ^
   $s2.WorkingDirectory = $projDir; ^
   $s2.Description = 'Stop Fine Backend on port 8000'; ^
   $s2.Save(); ^
   ^
   $s3 = $ws.CreateShortcut((Join-Path $startup 'Fine_Backend_Autostart.lnk')); ^
   $s3.TargetPath = 'wscript.exe'; ^
   $s3.Arguments = '\"' + $vbsPath + '\"'; ^
   $s3.WorkingDirectory = $projDir; ^
   if (Test-Path $iconPath) { $s3.IconLocation = $iconPath; }; ^
   $s3.Description = 'Fine Backend Autostart'; ^
   $s3.Save();"

if %ERRORLEVEL% equ 0 (
    echo [OK] Desktop shortcuts created:
    echo      - Start Fine Backend.lnk
    echo      - Stop Fine Backend.lnk
    echo [OK] Autostart shortcut installed in Windows Startup folder:
    echo      - Fine_Backend_Autostart.lnk
) else (
    echo [ERROR] Failed to create shortcuts. Please check permissions.
)

echo.
set /p START_NOW="Do you want to start the backend right now in background? (Y/N): "
if /i "!START_NOW!"=="Y" (
    wscript.exe "%~dp0start_backend_hidden.vbs"
    echo [OK] Backend start signal sent. Running on 192.168.2.200:8000 in background.
)

echo.
echo Setup completed. Press any key to exit.
pause >nul
