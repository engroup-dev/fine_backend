@echo off
setlocal enabledelayedexpansion
title Stop Fine Backend
echo Stopping Fine Backend on port 8000...
echo.

set FOUND=0
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":8000" ^| findstr "LISTENING"') do (
    set FOUND=1
    echo Terminating process PID %%a...
    taskkill /F /PID %%a >nul 2>&1
)

if "!FOUND!"=="1" (
    echo [OK] Backend stopped successfully.
) else (
    echo [INFO] No active process was found listening on port 8000.
)

timeout /t 3 >nul
