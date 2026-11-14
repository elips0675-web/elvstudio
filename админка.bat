@echo off
title Elv Studio - админка
chcp 866 >nul
setlocal
cd /d "%~dp0"

set "PORT=8090"
set "SITE=http://localhost:%PORT%/"
set "ADMIN=http://localhost:%PORT%/admin"

powershell -NoProfile -Command "if (Get-NetTCPConnection -State Listen -LocalPort %PORT% -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }" >nul 2>&1
if errorlevel 1 (
    echo Сервер на %PORT% не запущен.
    echo Сначала запустите "запуск.bat", затем повторите.
    echo.
    pause
    exit /b 1
)

echo Открываю админку: %ADMIN%
echo Логин: admin
echo Пароль: см. .local\admin-credentials.md
echo.
powershell -NoProfile -Command "Start-Process '%ADMIN%'" >nul 2>&1
exit /b 0
