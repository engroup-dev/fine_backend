@echo off
setlocal
title Fine Backend - Setup Autostart and Desktop Shortcut
cd /d "%~dp0"

set "FINE_HOST=192.168.2.200"
set "FINE_PORT=8000"

echo ========================================================
echo         Fine Backend - Setup Autostart ^& Shortcut
echo ========================================================
echo.

if exist "%~dp0artisan" (
    set "BACKEND_DIR=%~dp0"
) else if exist "%~dp0fine_backend\artisan" (
    set "BACKEND_DIR=%~dp0fine_backend\"
) else (
    echo [ERROR] Cannot locate backend directory.
    pause
    exit /b 1
)

echo Target Host : %FINE_HOST%
echo Target Port : %FINE_PORT%
echo Directory   : "%BACKEND_DIR%"
echo.

:: Check PHP in PATH
where php >nul 2>&1
if errorlevel 1 (
    echo [WARNING] 'php' command was not found in your system PATH.
    echo The autostart entry will be created, but the backend cannot start until
    echo PHP is installed and added to PATH.
    echo.
) else (
    echo [OK] PHP executable detected.
)

:: Paths go to PowerShell through environment variables, so quotes or other
:: special characters in the folder name can't break the PowerShell code.
set "FINE_SCRIPT_DIR=%~dp0"
set "FINE_ICON=%BACKEND_DIR%public\favicon.ico"

echo Creating Desktop shortcuts and Windows Startup entry...
:: Each quoted chunk must close its quotes before the ^ or cmd ends the command there.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$ErrorActionPreference = 'Stop';" ^
  "$ws = New-Object -ComObject WScript.Shell;" ^
  "$dir = $env:FINE_SCRIPT_DIR.TrimEnd('\');" ^
  "$wscript = Join-Path $env:SystemRoot 'System32\wscript.exe';" ^
  "$vbsArg = [char]34 + (Join-Path $dir 'start_backend_hidden.vbs') + [char]34;" ^
  "$hasIcon = Test-Path -LiteralPath $env:FINE_ICON;" ^
  "function New-Link($path, $target, $arguments, $description, $useIcon) {" ^
  "  $s = $ws.CreateShortcut($path);" ^
  "  $s.TargetPath = $target;" ^
  "  $s.Arguments = $arguments;" ^
  "  $s.WorkingDirectory = $dir;" ^
  "  if ($useIcon) { $s.IconLocation = $env:FINE_ICON };" ^
  "  $s.Description = $description;" ^
  "  $s.Save()" ^
  "};" ^
  "$desktop = [Environment]::GetFolderPath('Desktop');" ^
  "$startup = [Environment]::GetFolderPath('Startup');" ^
  "New-Link (Join-Path $desktop 'Start Fine Backend.lnk') $wscript $vbsArg ('Start Fine Backend on {0}:{1} in background' -f $env:FINE_HOST, $env:FINE_PORT) $hasIcon;" ^
  "New-Link (Join-Path $desktop 'Stop Fine Backend.lnk') (Join-Path $dir 'stop_backend.bat') '' ('Stop Fine Backend on port {0}' -f $env:FINE_PORT) $false;" ^
  "New-Link (Join-Path $startup 'Fine_Backend_Autostart.lnk') $wscript $vbsArg 'Fine Backend Autostart' $hasIcon"

if errorlevel 1 (
    echo [ERROR] Failed to create shortcuts. See the PowerShell error above.
) else (
    echo [OK] Desktop shortcuts created:
    echo      - Start Fine Backend.lnk
    echo      - Stop Fine Backend.lnk
    echo [OK] Autostart shortcut installed in Windows Startup folder:
    echo      - Fine_Backend_Autostart.lnk
)

:: Laravel's scheduler (nightly backup, pay-terms sync, monthly depreciation and
:: restore test) needs something to call it every minute. Like the backend, the
:: task runs as this user while logged on, so it needs no admin rights.
echo.
echo Registering the "Fine Backend Scheduler" task...
set "SCHED_TASK=Fine Backend Scheduler"
set "SCHED_VBS=%~dp0run_scheduler_hidden.vbs"
schtasks /Create /TN "%SCHED_TASK%" /TR "\"%SystemRoot%\System32\wscript.exe\" \"%SCHED_VBS%\"" /SC MINUTE /MO 1 /F >nul
if errorlevel 1 goto sched_failed
:: Keep running on battery, never start a second copy, stop a stuck run after an hour
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$s = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1);" ^
  "Set-ScheduledTask -TaskName $env:SCHED_TASK -Settings $s | Out-Null" >nul 2>&1
echo [OK] Scheduled jobs task installed (runs every minute while this user is logged on).
goto sched_done
:sched_failed
echo [ERROR] Could not create the scheduled jobs task. Nightly backups will not run.
:sched_done

echo.
set "START_NOW="
set /p START_NOW="Do you want to start the backend right now in background? (Y/N): "
if /i "%START_NOW%"=="Y" (
    wscript.exe "%~dp0start_backend_hidden.vbs"
    echo [OK] Backend start signal sent. It should be reachable at http://%FINE_HOST%:%FINE_PORT% within a minute.
)

echo.
echo Setup completed. Press any key to exit.
pause >nul
