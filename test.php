<?php
# -*- coding: utf-8 -*-

echo "=== TEST SCRIPT STARTED ===\n";

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация логгера
$logger = new Logger($config);

echo "=== Logger initialized ===\n";

$logger->info('=== TEST SCRIPT LOG ===', ['test' => 'script is running']);

echo "=== Test completed ===\n";
