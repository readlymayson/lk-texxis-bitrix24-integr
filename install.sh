#!/bin/bash
# -*- coding: utf-8 -*-

error() {
    echo
    echo ❌ Установка прервана из-за ошибок!
    echo Проверьте логи выше и исправьте проблемы.
    echo
    read -p "Нажмите Enter для выхода..."
    exit 1
}

echo ========================================
echo Установка интеграции Битрикс24
echo ========================================

# Создание необходимых директорий
if [ ! -d "src/logs" ]; then
    mkdir -p "src/logs"
fi

# Настройка .env файла
if [ ! -f ".env" ]; then
    if [ -f ".env.example" ]; then
        echo Копирование .env.example в .env...
        cp ".env.example" ".env"
        echo ✓ Создан файл .env из шаблона
        echo
        echo ВАЖНО: Отредактируйте файл .env и укажите реальные значения!
        echo
    else
        echo ✗ Файл .env.example не найден!
    fi
else
    echo ✓ Файл .env уже существует
fi

# Установка прав доступа
chmod 755 src/webhooks/bitrix24.php 2>/dev/null

# Проверка конфигурации
if [ -f "src/config/bitrix24.php" ]; then
    echo ✓ Конфигурационный файл найден
else
    echo ✗ Конфигурационный файл не найден!
    error
fi

# Проверка PHP
if command -v php >/dev/null 2>&1; then
    echo ✓ PHP установлен
else
    echo ✗ PHP не найден! Установите PHP 7.4+
    echo Попытка автоматической установки PHP...
    if command -v apt >/dev/null 2>&1; then
        sudo apt update && sudo apt install -y php php-cli php-mbstring php-curl
        if command -v php >/dev/null 2>&1; then
            echo ✓ PHP успешно установлен
        else
            error
        fi
    else
        error
    fi
fi

# Тестирование конфигурации
echo Тестирование конфигурации...
if php -l src/config/bitrix24.php >/dev/null 2>&1; then
    echo ✓ Синтаксис конфигурации корректен
else
    echo ✗ Ошибка в конфигурационном файле!
    error
fi

# Тестирование классов
if php -l src/classes/Logger.php >/dev/null 2>&1; then
    echo ✓ Класс Logger загружен
else
    echo ✗ Ошибка в классе Logger!
    error
fi

if php -l src/classes/Bitrix24API.php >/dev/null 2>&1; then
    echo ✓ Класс Bitrix24API загружен
else
    echo ✗ Ошибка в классе Bitrix24API!
    error
fi

if php -l src/classes/LKAPI.php >/dev/null 2>&1; then
    echo ✓ Класс LKAPI загружен
else
    echo ✗ Ошибка в классе LKAPI!
    error
fi

# Тестирование основного обработчика
if php -l src/webhooks/bitrix24.php >/dev/null 2>&1; then
    echo ✓ Основной обработчик корректен
else
    echo ✗ Ошибка в основном обработчике!
    error
fi

echo
echo ========================================
echo ✓ Установка завершена успешно!
echo ========================================
echo
echo Следующие шаги:
echo 1. Отредактируйте src/config/bitrix24.php
echo 2. Настройте вебхуки в Битрикс24
echo 3. Разместите файлы на веб-сервере
echo 4. Протестируйте интеграцию
echo
echo Документация: README.md
echo
read -p "Нажмите Enter для выхода..."

exit 0
