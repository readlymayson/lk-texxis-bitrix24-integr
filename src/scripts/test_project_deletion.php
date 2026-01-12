<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для тестирования удаления проекта через webhook
 *
 * Использование:
 * php test_project_deletion.php [project_id]
 *
 * Параметры:
 * - project_id: ID существующего проекта для удаления.
 *               Если не указан, будет создан тестовый проект с ID=999999 и затем удален.
 *
 * Примеры:
 * php test_project_deletion.php        # Создать и удалить тестовый проект с ID=999999
 * php test_project_deletion.php 123    # Удалить существующий проект с ID=123
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';
require_once __DIR__ . '/../webhooks/bitrix24.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

// Парсинг аргументов командной строки
$projectId = $argv[1] ?? '999999'; // ID проекта (по умолчанию 999999 для тестового)
$createTestProject = ($projectId === '999999'); // Создаем тестовый проект только если ID по умолчанию

echo "=== ТЕСТИРОВАНИЕ УДАЛЕНИЯ ПРОЕКТА ===\n\n";
echo "Project ID: {$projectId}\n";
echo "Create test project: " . ($createTestProject ? 'YES' : 'NO') . "\n\n";

// Создаем тестовый проект, если используется ID по умолчанию
if ($createTestProject) {
    echo "1. Создание тестового проекта...\n";

    $testProjectData = [
        'bitrix_id' => $projectId,
        'organization_name' => 'Тестовая организация',
        'object_name' => 'Тестовый объект',
        'system_types' => ['Тестовая система 1', 'Тестовая система 2'],
        'location' => 'г. Москва, ул. Тестовая, д. 1',
        'implementation_date' => '2024-12-25',
        'request_type' => 'Тестовый запрос',
        'equipment_list' => [
            ['id' => '123', 'name' => 'Тестовый файл.pdf', 'url' => 'https://example.com/test.pdf', 'size' => 1024000],
            ['id' => '456', 'name' => 'Тестовый файл 2.pdf', 'url' => 'https://example.com/test2.pdf', 'size' => 2048000]
        ],
        'competitors' => 'Тестовые конкуренты',
        'marketing_discount' => true,
        'technical_description' => 'Тестовое техническое описание проекта',
        'status' => 'NEW',
        'client_id' => '1',
        'manager_id' => '1'
    ];

    $createResult = $localStorage->addProject($testProjectData);

    if ($createResult) {
        echo "✓ Тестовый проект успешно создан\n";
    } else {
        echo "✗ Ошибка при создании тестового проекта\n";
        exit(1);
    }
} else {
    echo "1. Использование существующего проекта (не создаем новый)...\n";
}

// Проверяем наличие проекта перед удалением
echo "\n2. Проверка наличия проекта перед удалением...\n";

$allProjects = $localStorage->getAllProjects();
$projectExistsBefore = isset($allProjects[$projectId]);

if ($projectExistsBefore) {
    echo "✓ Проект найден в локальном хранилище\n";
    echo "  Организация: " . ($allProjects[$projectId]['organization_name'] ?? 'не указана') . "\n";
    echo "  Объект: " . ($allProjects[$projectId]['object_name'] ?? 'не указан') . "\n";
} else {
    if ($createTestProject) {
        echo "✗ Проект не найден в локальном хранилище\n";
        echo "Возможно, произошла ошибка при создании тестового проекта\n";
        exit(1);
    } else {
        echo "ℹ Проект с ID {$projectId} не найден в локальном хранилище\n";
        echo "Продолжаем тест - webhook все равно должен корректно обработать запрос\n";
    }
}

// Отправляем webhook запрос на удаление проекта
echo "\n3. Отправка webhook запроса ONCRMDYNAMICITEMDELETE...\n";

$webhookUrl = getenv('WEBHOOK_TEST_URL') ?: 'https://efrolov-dev.ru/application/lk/src/webhooks/bitrix24.php';
$applicationToken = getenv('BITRIX24_APPLICATION_TOKEN') ?: '';

$testData = [
    'event' => 'ONCRMDYNAMICITEMDELETE',
    'event_handler_id' => '999',
    'data' => [
        'FIELDS' => [
            'ID' => $projectId,
            'ENTITY_TYPE_ID' => $config['bitrix24']['smart_process_id'] ?? '1038'
        ]
    ],
    'ts' => time(),
    'auth' => [
        'domain' => 'b24-11ue58.bitrix24.ru',
        'client_endpoint' => 'https://b24-11ue58.bitrix24.ru/rest/',
        'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
        'member_id' => '42d6c4c35f73b1c45de11528bd16c826',
    ]
];

if (!empty($applicationToken)) {
    $testData['auth']['application_token'] = $applicationToken;
}

$postData = http_build_query($testData);

echo "Webhook URL: {$webhookUrl}\n";
echo "Отправляемые данные:\n";
echo json_encode($testData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

$ch = curl_init($webhookUrl);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Bitrix24 Webhook Engine',
        'Content-Length: ' . strlen($postData)
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 30
]);

$startTime = microtime(true);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$totalTime = round((microtime(true) - $startTime) * 1000, 2);
curl_close($ch);

if ($error) {
    echo "✗ Ошибка CURL: {$error}\n";
    exit(1);
}

echo "HTTP код: {$httpCode}\n";
echo "Время ответа: {$totalTime} мс\n";

if ($httpCode === 200) {
    echo "✓ Webhook запрос успешно обработан\n";
} else {
    echo "✗ Webhook запрос вернул ошибку (код: {$httpCode})\n";
    echo "Ответ сервера: {$response}\n";
    exit(1);
}

// Проверяем удаление проекта
echo "\n4. Проверка удаления проекта...\n";

$allProjectsAfter = $localStorage->getAllProjects();
$projectExistsAfter = isset($allProjectsAfter[$projectId]);

if (!$projectExistsAfter) {
    if ($projectExistsBefore) {
        echo "✓ Проект успешно удален из локального хранилища\n";
    } else {
        echo "✓ Проект отсутствовал до и после теста (корректная обработка)\n";
    }
    echo "\n=== ТЕСТ ПРОШЕЛ УСПЕШНО ===\n";
} else {
    echo "✗ Проект все еще существует в локальном хранилище\n";
    echo "Данные проекта:\n";
    print_r($allProjectsAfter[$projectId]);
    echo "\n=== ТЕСТ ПРОВАЛЕН ===\n";
    exit(1);
}

echo "\nРекомендации:\n";
echo "- Проверьте файл логов: src/logs/bitrix24_webhooks.log\n";
echo "- Убедитесь, что webhook endpoint доступен\n";
echo "- Проверьте настройки BITRIX24_APPLICATION_TOKEN в .env файле\n";

// Тест функции mapProjectData
echo "\n\n=== ТЕСТ ФУНКЦИИ mapProjectData ===\n";

$testProjectData = [
    'id' => '999999',
    'contactId' => '3', // Контакт с company=9 согласно contacts.json
    'ufCrm7_1768130049371' => 'Тестовая организация',
    'ufCrm7_1768130056401' => 'Тестовый объект'
];

$mapping = $config['field_mapping']['smart_process'];
$result = mapProjectData($testProjectData, $mapping, $logger, $localStorage);

echo "Тестовые данные проекта:\n";
echo "- ID: {$testProjectData['id']}\n";
echo "- contactId: {$testProjectData['contactId']}\n";

echo "\nРезультат маппинга:\n";
echo "- client_id: " . ($result['client_id'] ?? 'null') . "\n";
echo "- company_id: " . ($result['company_id'] ?? 'null') . "\n";

if ($result['company_id'] === '9') {
    echo "\n✓ ТЕСТ ПРОШЕЛ: company_id корректно извлечен из данных контакта\n";
} else {
    echo "\n✗ ТЕСТ НЕ ПРОШЕЛ: company_id не соответствует ожидаемому значению\n";
}
