@echo off
setlocal
:: Runs Laravel's scheduler once (nightly backup, pay-terms sync, monthly
:: depreciation and restore test). The "Fine Backend Scheduler" Windows task
:: created by setup_autostart.bat calls this every minute, hidden.

if exist "%~dp0artisan" (
    set "BACKEND_DIR=%~dp0"
) else if exist "%~dp0fine_backend\artisan" (
    set "BACKEND_DIR=%~dp0fine_backend\"
) else (
    exit /b 1
)
cd /d "%BACKEND_DIR%"

set "LOG_DIR=%BACKEND_DIR%storage\logs"
set "LOG_FILE=%LOG_DIR%\scheduler.log"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

where php >nul 2>&1
if errorlevel 1 (
    >>"%LOG_FILE%" echo [%date% %time%] ERROR: 'php' was not found in PATH.
    exit /b 1
)

:: One line a minute adds up: roll the log over at 5 MB
if exist "%LOG_FILE%" for %%F in ("%LOG_FILE%") do if %%~zF GTR 5242880 move /y "%LOG_FILE%" "%LOG_FILE%.old" >nul

php artisan schedule:run >> "%LOG_FILE%" 2>&1
