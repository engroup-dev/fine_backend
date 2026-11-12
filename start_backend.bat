@echo off
setlocal
if exist "%~dp0artisan" (
    cd /d "%~dp0"
    set "TARGET_DIR=%~dp0"
) else if exist "%~dp0fine_backend\artisan" (
    cd /d "%~dp0fine_backend"
    set "TARGET_DIR=%~dp0fine_backend\"
) else (
    echo [ERROR] artisan not found.
    exit /b 1
)

:: Check if port 8000 is already in use
netstat -aon | findstr ":8000" | findstr "LISTENING" >nul 2>&1
if %ERRORLEVEL% equ 0 (
    echo Fine Backend is already running on port 8000.
    exit /b 0
)

:: Ensure logs directory exists
if not exist "%TARGET_DIR%storage\logs" (
    mkdir "%TARGET_DIR%storage\logs"
)

:: Start Laravel server and redirect logs
php artisan serve --host=192.168.2.200 --port=8000 >> "%TARGET_DIR%storage\logs\artisan_serve.log" 2>&1
