<?php
# -*- coding: utf-8 -*-

/**
 * Тестовый скрипт для проверки маппинга полей смарт-процессов
 */

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/EnvLoader.php';
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/LKAPI.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$lkApi = new LKAPI($config, $logger);
$localStorage = new LocalStorage($logger);

echo "=== ТЕСТИРОВАНИЕ МАППИНГА ПОЛЕЙ СМАРТ-ПРОЦЕССОВ ===\n\n";

// Пример данных смарт-процесса (как возвращает Bitrix24 API)
$smartProcessData = [
    'id' => '2',  // Маленькими буквами!
    'xmlId' => null,
    'title' => 'Тестовый проект',
    'createdBy' => 1,
    'updatedBy' => 1,
    'movedBy' => null,
    'createdTime' => '2025-11-22T12:00:00+03:00',
    'updatedTime' => '2025-11-22T12:05:00+03:00',
    'movedTime' => null,
    'categoryId' => 0,
    'opened' => 'Y',
    'stageId' => 'DT123_1:NEW',
    'previousStageId' => null,
    'begindate' => null,
    'closedate' => null,
    'companyId' => null,
    'contactId' => '999',  // ID контакта
    'opportunity' => '100000',
    'isManualOpportunity' => 'N',
    'taxValue' => null,
    'currencyId' => 'RUB',
    'mycompanyId' => 1,
    'sourceId' => null,
    'sourceDescription' => null,
    'webformId' => null,
    'ufCrm6_1758957874' => 'ООО Тестовая компания',
    'ufCrm6_1758958190' => 'Тестовый объект',
    'ufCrm6_1758959081' => 'Система безопасности',
    'ufCrm6_1758958310' => 'г. Москва, ул. Тестовая',
    'ufCrm6_1758959105' => '2025-12-01',
    'assignedById' => 1,
    'isRecurring' => 'N',
    'lastActivityBy' => 1,
    'lastActivityTime' => '2025-11-22T12:05:00+03:00',
    'lastCommunicationTime' => null,
    'lastCommunicationCallTime' => null,
    'lastCommunicationEmailTime' => null,
    'lastCommunicationImolTime' => null,
    'lastCommunicationWebformTime' => null,
    'utmSource' => null,
    'utmMedium' => null,
    'utmCampaign' => null,
    'utmContent' => null,
    'utmTerm' => null,
    'observers' => null,
    'contactIds' => ['999'],
    'entityTypeId' => 1036
];

echo "📊 ИСХОДНЫЕ ДАННЫЕ СМАРТ-ПРОЦЕССА:\n";
echo "• ID: {$smartProcessData['id']}\n";
echo "• Title: {$smartProcessData['title']}\n";
echo "• Contact ID: {$smartProcessData['contactId']}\n";
echo "• Stage: {$smartProcessData['stageId']}\n\n";

echo "🔄 МАППИНГ ПОЛЕЙ:\n";
$reflection = new ReflectionClass($lkApi);
$method = $reflection->getMethod('mapProjectFields');
$method->setAccessible(true);
$mappedData = $method->invoke($lkApi, $smartProcessData);
echo "• bitrix_id: {$mappedData['bitrix_id']}\n";
echo "• client_id: {$mappedData['client_id']}\n";
echo "• organization_name: {$mappedData['organization_name']}\n";
echo "• object_name: {$mappedData['object_name']}\n";
echo "• status: {$mappedData['status']}\n\n";

echo "💾 СОХРАНЕНИЕ В ЛОКАЛЬНОЕ ХРАНИЛИЩЕ:\n";
$result = $localStorage->addProject($mappedData);
if ($result) {
    echo "✅ Проект успешно сохранен\n";
} else {
    echo "❌ Ошибка сохранения проекта\n";
}

echo "\n📂 ПРОВЕРКА СОХРАНЕННЫХ ДАННЫХ:\n";
$projects = $localStorage->getAllProjects();
if (!empty($projects)) {
    foreach ($projects as $id => $project) {
        echo "• Ключ: '$id', Bitrix ID: {$project['bitrix_id']}\n";
        echo "  Организация: {$project['organization_name']}\n";
        echo "  Клиент: {$project['client_id']}\n";
    }
} else {
    echo "❌ Нет сохраненных проектов\n";
}

echo "\n🎯 ВЫВОД:\n";
if ($mappedData['bitrix_id'] !== null && $mappedData['client_id'] !== null) {
    echo "✅ Маппинг работает правильно!\n";
} else {
    echo "❌ Проблемы с маппингом полей\n";
}

echo "\nТест завершен.\n";
?>
