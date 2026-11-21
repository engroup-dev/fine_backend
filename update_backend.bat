@echo off
setlocal

:: git pull may rewrite this file while cmd is still reading it, so run from a temp copy
if /i not "%~1"=="--from-temp" (
    copy /y "%~f0" "%TEMP%\fine_update_backend.bat" >nul
    "%TEMP%\fine_update_backend.bat" --from-temp "%~dp0"
    exit /b
)
set "SCRIPT_DIR=%~2"

title Fine Backend - Update
echo ========================================================
echo                 Fine Backend - Update
echo ========================================================
echo.

if exist "%SCRIPT_DIR%artisan" (
    set "BACKEND_DIR=%SCRIPT_DIR%"
) else if exist "%SCRIPT_DIR%fine_backend\artisan" (
    set "BACKEND_DIR=%SCRIPT_DIR%fine_backend\"
) else (
    echo [ERROR] Cannot locate backend directory.
    pause
    exit /b 1
)

where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] 'php' was not found in PATH.
    pause
    exit /b 1
)
where git >nul 2>&1
if errorlevel 1 (
    echo [ERROR] 'git' was not found in PATH.
    pause
    exit /b 1
)

cd /d "%BACKEND_DIR%"

echo [1/6] Stopping the backend...
call "%SCRIPT_DIR%stop_backend.bat"
echo.

echo [2/6] Pulling the latest code...
for /f %%H in ('git rev-parse HEAD') do set "OLD_HEAD=%%H"
git pull --ff-only
if errorlevel 1 (
    echo.
    echo [ERROR] git pull failed. Nothing was changed; restarting the old version.
    wscript.exe "%SCRIPT_DIR%start_backend_hidden.vbs"
    pause
    exit /b 1
)
echo.

echo [3/6] Checking PHP packages...
set "NEED_COMPOSER=0"
if not exist "vendor\autoload.php" set "NEED_COMPOSER=1"
git diff --quiet %OLD_HEAD% HEAD -- composer.lock
if errorlevel 1 set "NEED_COMPOSER=1"
if "%NEED_COMPOSER%"=="0" (
    echo [OK] No package changes.
    goto composer_done
)
where composer >nul 2>&1
if errorlevel 1 (
    echo [ERROR] composer.lock changed but 'composer' was not found in PATH.
    echo Install Composer, then run this script again.
    pause
    exit /b 1
)
call composer install --no-dev --optimize-autoloader --no-interaction
if errorlevel 1 (
    echo [ERROR] composer install failed. See the messages above.
    pause
    exit /b 1
)
:composer_done
echo.

echo [4/6] Backing up the database...
set "SNAPSHOT_OUT=%TEMP%\fine_snapshot_path.txt"
php artisan db:snapshot > "%SNAPSHOT_OUT%"
if errorlevel 1 (
    type "%SNAPSHOT_OUT%"
    echo [ERROR] The database backup failed, so the update stopped before changing the database.
    pause
    exit /b 1
)
set /p SNAPSHOT=<"%SNAPSHOT_OUT%"
echo [OK] Backup: "%SNAPSHOT%"
echo.

echo [5/6] Updating the database...
php artisan migrate --force
if errorlevel 1 (
    echo.
    echo [ERROR] The database update failed. The backend was NOT restarted.
    echo The database before this update is saved at:
    echo   "%SNAPSHOT%"
    echo Send the error above to support before starting the backend again.
    pause
    exit /b 1
)
php artisan optimize:clear >nul
:: Read-only report: treasuries linked in the wrong cash/bank branch
php artisan ledger:check-treasury-links
echo.

echo [6/6] Starting the backend...
wscript.exe "%SCRIPT_DIR%start_backend_hidden.vbs"
echo [OK] Update finished.

echo.
pause
