<?php
# -*- coding: utf-8 -*-

/**
 * Тестовый скрипт для проверки привязки всех сущностей по bitrix_id
 */

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/EnvLoader.php';
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';
require_once __DIR__ . '/src/classes/LKAPI.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$localStorage = new LocalStorage($logger);
$lkApi = new LKAPI($config, $logger);

echo "=== Тестирование привязки сущностей по bitrix_id ===\n\n";

// Тест контактов
echo "1. КОНТАКТЫ:\n";
$testContactIds = ['2', '999', '100', '9999']; // 9999 - несуществующий

foreach ($testContactIds as $contactId) {
    $contact = $localStorage->getContact($contactId);
    if ($contact) {
        echo "   ✅ Контакт {$contactId}: {$contact['name']} {$contact['last_name']} (LK: {$contact['id']})\n";
    } else {
        echo "   ❌ Контакт {$contactId}: НЕ НАЙДЕН\n";
    }
}

// Тест компаний
echo "\n2. КОМПАНИИ:\n";
$testCompanyIds = ['0', '1', '999']; // Проверим существующие

foreach ($testCompanyIds as $companyId) {
    $company = $localStorage->getCompany($companyId);
    if ($company) {
        echo "   ✅ Компания {$companyId}: {$company['title']}\n";
    } else {
        echo "   ❌ Компания {$companyId}: НЕ НАЙДЕНА\n";
    }
}

// Тест проектов
echo "\n3. ПРОЕКТЫ:\n";
$projects = $localStorage->getAllProjects();
echo "   Всего проектов: " . count($projects) . "\n";
foreach ($projects as $projectId => $project) {
    $clientId = $project['client_id'] ?? null;
    $client = $clientId ? $localStorage->getContact($clientId) : null;
    $clientName = $client ? "{$client['name']} {$client['last_name']}" : 'НЕТ КЛИЕНТА';
    echo "   📋 Проект {$projectId}: {$project['organization_name']} → Клиент: {$clientName}\n";
}

// Тест сделок
echo "\n4. СДЕЛКИ:\n";
$deals = $localStorage->getAllDeals();
echo "   Всего сделок: " . count($deals) . "\n";

echo "\n=== Проверка функций API ===\n";

// Проверка, что функции существуют
$functions = [
    'LKAPI::syncContactByBitrixId',
    'LKAPI::syncCompanyByBitrixId',
    'LKAPI::syncProjectByClient',
    'LocalStorage::syncContactByBitrixId',
    'LocalStorage::syncCompanyByBitrixId',
];

foreach ($functions as $function) {
    list($class, $method) = explode('::', $function);
    if (method_exists($class === 'LKAPI' ? $lkApi : $localStorage, $method)) {
        echo "   ✅ {$function} - существует\n";
    } else {
        echo "   ❌ {$function} - НЕ существует\n";
    }
}

echo "\nТест завершен.\n";
?>
