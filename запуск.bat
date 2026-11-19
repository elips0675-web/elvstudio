@echo off
title Elv Studio - запуск сайта
chcp 866 >nul
setlocal EnableDelayedExpansion
cd /d "%~dp0"

set "PORT=8090"
set "HOST=0.0.0.0"
set "SITE=http://localhost:%PORT%/"
set "ADMIN=http://localhost:%PORT%/admin"

echo.
echo  Elv Studio - October CMS
echo  Каталог: %~dp0
echo.

if not exist "artisan" (
    echo [ОШИБКА] artisan не найден. Запускайте файл из корня проекта.
    echo.
    pause
    exit /b 1
)

if not exist ".env" (
    echo [ОШИБКА] Нет файла .env.
    echo Скопируйте .env.example в .env и выполните: php artisan key:generate
    echo.
    pause
    exit /b 1
)

rem --- PHP: сначала 8.4/8.3/8.2, затем любой из Laragon/XAMPP ---
set "PHP="
where php >nul 2>&1
if not errorlevel 1 (
    for /f "delims=" %%P in ('where php') do (
        if not defined PHP set "PHP=%%P"
    )
)

if not defined PHP (
    for %%V in (8.4 8.3 8.2) do (
        if not defined PHP (
            for /d %%D in ("C:\laragon\bin\php\php-%%V*" "D:\laragon\bin\php\php-%%V*") do (
                if exist "%%~D\php.exe" set "PHP=%%~D\php.exe"
            )
        )
    )
)

if not defined PHP (
    for /d %%D in ("C:\laragon\bin\php\php-*" "D:\laragon\bin\php\php-*") do (
        if exist "%%~D\php.exe" set "PHP=%%~D\php.exe"
    )
)

if not defined PHP (
    if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
    if exist "C:\OpenServer\modules\php\php.exe" set "PHP=C:\OpenServer\modules\php\php.exe"
)

if not defined PHP (
    echo [ОШИБКА] PHP не найден.
    echo Установите PHP 8.3+ или добавьте php.exe в PATH.
    echo.
    pause
    exit /b 1
)

echo [1/3] PHP: %PHP%

rem --- MySQL 3306 ---
powershell -NoProfile -Command "if (Get-NetTCPConnection -State Listen -LocalPort 3306 -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }" >nul 2>&1
if errorlevel 1 (
    echo.
    echo [ОШИБКА] MySQL не слушает порт 3306.
    echo Запустите MySQL в Laragon и повторите.
    echo.
    pause
    exit /b 1
)
echo [2/3] MySQL: 3306 - работает

rem --- Сервер уже запущен? ---
powershell -NoProfile -Command "if (Get-NetTCPConnection -State Listen -LocalPort %PORT% -ErrorAction SilentlyContinue) { exit 0 } else { exit 1 }" >nul 2>&1
if not errorlevel 1 (
    echo [3/3] Сервер уже слушает %PORT% - открываю браузер.
    powershell -NoProfile -Command "Start-Process '%SITE%'" >nul 2>&1
    echo.
    echo  Сайт:    %SITE%
    echo  Админка: %ADMIN%
    echo  Логин:   admin
    echo  Пароль:  см. .local\admin-credentials.md
    echo.
    pause
    exit /b 0
)

echo [3/3] Запуск сервера: %SITE%
echo.
echo  Браузер откроется через 5 секунд.
echo  Это окно держит сервер: закроете его - сайт остановится.
echo  Остановить: Ctrl+C.
echo.

powershell -NoProfile -Command "Start-Process -WindowStyle Hidden powershell -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','%~dp0tools\warmup.ps1','-Port','%PORT%','-Site','%SITE%'" >nul 2>&1

for %%I in ("%PHP%") do set "PHP_DIR=%%~dpI"
set "OPCACHE=%PHP_DIR%ext\php_opcache.dll"
if exist "%OPCACHE%" (
    echo  OPcache: включён
    "%PHP%" -d zend_extension="%OPCACHE%" -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.memory_consumption=192 -d opcache.max_accelerated_files=20000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0 -S %HOST%:%PORT% server.php
) else (
    echo  OPcache: не найден, работаю без него
    "%PHP%" -S %HOST%:%PORT% server.php
)

echo.
echo Сервер остановлен.
pause
