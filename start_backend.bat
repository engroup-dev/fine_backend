@echo off
setlocal
set "FINE_HOST=192.168.2.200"
set "FINE_PORT=8000"

if exist "%~dp0artisan" (
    set "BACKEND_DIR=%~dp0"
) else if exist "%~dp0fine_backend\artisan" (
    set "BACKEND_DIR=%~dp0fine_backend\"
) else (
    echo [ERROR] artisan not found next to this script or in fine_backend\.
    exit /b 1
)
cd /d "%BACKEND_DIR%"

set "LOG_DIR=%BACKEND_DIR%storage\logs"
set "LOG_FILE=%LOG_DIR%\artisan_serve.log"
if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

:: When launched hidden at logon nobody sees the console, so failures go to the log
where php >nul 2>&1
if errorlevel 1 (
    >>"%LOG_FILE%" echo [%date% %time%] ERROR: 'php' was not found in PATH.
    echo [ERROR] 'php' was not found in PATH.
    exit /b 1
)

:: Warn (never auto-migrate) when the database is behind the code: requests that
:: touch the missing tables or columns fail with "Server error"
php artisan migrate:status --pending=1 >nul 2>&1
if errorlevel 1 (
    >>"%LOG_FILE%" echo [%date% %time%] WARNING: the database has pending migrations. Run update_backend.bat.
    echo [WARNING] The database has pending migrations. Run update_backend.bat.
)

:: Check if port is already in use (Get-NetTCPConnection avoids parsing localized netstat output)
powershell -NoProfile -Command "if (Get-NetTCPConnection -State Listen -LocalPort %FINE_PORT% -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }"
if not errorlevel 1 (
    echo Fine Backend is already running on port %FINE_PORT%.
    exit /b 0
)

:: At logon the network adapter may not have its address yet; wait up to ~60s for it
set /a TRIES=0
:wait_for_ip
ipconfig | findstr /C:"%FINE_HOST%" >nul && goto ip_ready
if %TRIES% geq 30 (
    >>"%LOG_FILE%" echo [%date% %time%] WARNING: %FINE_HOST% is not assigned to this machine yet, starting anyway.
    goto ip_ready
)
set /a TRIES+=1
ping -n 3 127.0.0.1 >nul
goto wait_for_ip
:ip_ready

:: Keep the request log from growing forever: roll it over at 10 MB
if exist "%LOG_FILE%" for %%F in ("%LOG_FILE%") do if %%~zF GTR 10485760 move /y "%LOG_FILE%" "%LOG_FILE%.old" >nul

echo Fine Backend is running on http://%FINE_HOST%:%FINE_PORT%
echo Log file: "%LOG_FILE%"
echo Close this window or run stop_backend.bat to stop it.

:: Start Laravel server and redirect logs
>>"%LOG_FILE%" echo [%date% %time%] Starting Fine Backend on %FINE_HOST%:%FINE_PORT%
php artisan serve --host=%FINE_HOST% --port=%FINE_PORT% >> "%LOG_FILE%" 2>&1
>>"%LOG_FILE%" echo [%date% %time%] Fine Backend exited with code %ERRORLEVEL%
