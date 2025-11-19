@echo off
chcp 65001 > nul
REM -*- coding: utf-8 -*-

echo ========================================
echo Установка интеграции Битрикс24
echo ========================================

REM Создание необходимых директорий
if not exist "src\logs" mkdir "src\logs"

REM Настройка .env файла
if not exist ".env" (
    if exist ".env.example" (
        echo Копирование .env.example в .env...
        copy ".env.example" ".env" >nul
        echo ✓ Создан файл .env из шаблона
        echo.
        echo ВАЖНО: Отредактируйте файл .env и укажите реальные значения!
        echo.
    ) else (
        echo ✗ Файл .env.example не найден!
    )
) else (
    echo ✓ Файл .env уже существует
)

REM Установка прав доступа (для Unix систем)
REM chmod 755 src/webhooks/bitrix24.php

REM Проверка конфигурации
if exist "src\config\bitrix24.php" (
    echo ✓ Конфигурационный файл найден
) else (
    echo ✗ Конфигурационный файл не найден!
    goto :error
)

REM Проверка PHP
php --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ PHP установлен
) else (
    echo ✗ PHP не найден! Установите PHP 7.4+
    goto :error
)

REM Тестирование конфигурации
echo Тестирование конфигурации...
php -l src/config/bitrix24.php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ Синтаксис конфигурации корректен
) else (
    echo ✗ Ошибка в конфигурационном файле!
    goto :error
)

REM Тестирование классов
php -l src/classes/Logger.php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ Класс Logger загружен
) else (
    echo ✗ Ошибка в классе Logger!
    goto :error
)

php -l src/classes/Bitrix24API.php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ Класс Bitrix24API загружен
) else (
    echo ✗ Ошибка в классе Bitrix24API!
    goto :error
)

php -l src/classes/LKAPI.php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ Класс LKAPI загружен
) else (
    echo ✗ Ошибка в классе LKAPI!
    goto :error
)

REM Тестирование основного обработчика
php -l src/webhooks/bitrix24.php >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo ✓ Основной обработчик корректен
) else (
    echo ✗ Ошибка в основном обработчике!
    goto :error
)

echo.
echo ========================================
echo ✓ Установка завершена успешно!
echo ========================================
echo.
echo Следующие шаги:
echo 1. Отредактируйте src/config/bitrix24.php
echo 2. Настройте вебхуки в Битрикс24
echo 3. Разместите файлы на веб-сервере
echo 4. Протестируйте интеграцию
echo.
echo Документация: README.md
echo.
pause
goto :eof

:error
echo.
echo ❌ Установка прервана из-за ошибок!
echo Проверьте логи выше и исправьте проблемы.
echo.
pause

