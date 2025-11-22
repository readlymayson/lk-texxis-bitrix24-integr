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
echo Создание директорий...
if [ ! -d "src/logs" ]; then
    mkdir -p "src/logs"
    echo ✓ Создана директория src/logs
else
    echo ✓ Директория src/logs уже существует
fi

if [ ! -d "src/data" ]; then
    mkdir -p "src/data"
    echo ✓ Создана директория src/data
else
    echo ✓ Директория src/data уже существует
fi

# Создание файлов данных
echo Создание файлов данных...
if [ ! -f "src/data/contacts.json" ]; then
    echo '{}' > "src/data/contacts.json"
    echo ✓ Создан файл src/data/contacts.json
else
    echo ✓ Файл src/data/contacts.json уже существует
fi

if [ ! -f "src/data/companies.json" ]; then
    echo '{}' > "src/data/companies.json"
    echo ✓ Создан файл src/data/companies.json
else
    echo ✓ Файл src/data/companies.json уже существует
fi

if [ ! -f "src/data/deals.json" ]; then
    echo '{}' > "src/data/deals.json"
    echo ✓ Создан файл src/data/deals.json
else
    echo ✓ Файл src/data/deals.json уже существует
fi

if [ ! -f "src/data/projects.json" ]; then
    echo '{}' > "src/data/projects.json"
    echo ✓ Создан файл src/data/projects.json
else
    echo ✓ Файл src/data/projects.json уже существует
fi

if [ ! -f "src/data/managers.json" ]; then
    echo '{}' > "src/data/managers.json"
    echo ✓ Создан файл src/data/managers.json
else
    echo ✓ Файл src/data/managers.json уже существует
fi

# Установка прав доступа
echo Установка прав доступа...
chmod 755 src/webhooks/bitrix24.php 2>/dev/null
chmod 666 src/data/contacts.json 2>/dev/null
chmod 666 src/data/companies.json 2>/dev/null
chmod 666 src/data/deals.json 2>/dev/null
chmod 666 src/data/projects.json 2>/dev/null
chmod 666 src/data/managers.json 2>/dev/null
chmod 755 src/data 2>/dev/null
chmod 755 src/logs 2>/dev/null

if [ -f "src/data/contacts.json" ] && [ -w "src/data/contacts.json" ] && \
   [ -f "src/data/companies.json" ] && [ -w "src/data/companies.json" ] && \
   [ -f "src/data/deals.json" ] && [ -w "src/data/deals.json" ] && \
   [ -f "src/data/projects.json" ] && [ -w "src/data/projects.json" ] && \
   [ -f "src/data/managers.json" ] && [ -w "src/data/managers.json" ]; then
    echo ✓ Права доступа установлены корректно
else
    echo ✗ Проблема с правами доступа к файлам данных!
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
