<?php
# -*- coding: utf-8 -*-

/**
 * Простой тест функции mapProjectData для проверки извлечения company_id
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';
$logger = new Logger($config);
$localStorage = new LocalStorage($logger, $config);

// Функция mapProjectData (копия из bitrix24.php для тестирования)
function testMapProjectData($projectData, $mapping, $logger, $localStorage = null)
{
    $projectId = $projectData['id'] ?? $projectData['ID'] ?? null;
    $clientId = extractContactId($projectData[$mapping['client_id']] ?? null);

    // Извлекаем company_id из данных контакта в локальном хранилище
    $companyId = null;
    if (!empty($clientId) && $localStorage) {
        $contactData = $localStorage->getContact($clientId);
        if ($contactData && isset($contactData['company'])) {
            $companyId = $contactData['company'];
            $logger->debug('Extracted company ID from contact data', [
                'contact_id' => $clientId,
                'company_id' => $companyId
            ]);
        }
    }

    return [
        'bitrix_id' => $projectId,
        'client_id' => $clientId,
        'company_id' => $companyId,
    ];
}

// Вспомогательная функция
function extractContactId($rawValue)
{
    if (is_array($rawValue)) {
        return !empty($rawValue) ? (string)$rawValue[0] : null;
    }
    return !empty($rawValue) ? (string)$rawValue : null;
}

echo "=== ТЕСТ ФУНКЦИИ mapProjectData ===\n\n";

// Тест 1: Контакт с ID=3 (должен вернуть company_id=9)
$testProjectData1 = [
    'id' => '999999',
    'contactId' => '3', // Контакт с company=9
];

$mapping = $config['field_mapping']['smart_process'];
$result1 = testMapProjectData($testProjectData1, $mapping, $logger, $localStorage);

echo "Тест 1 - Контакт ID=3:\n";
echo "- client_id: " . ($result1['client_id'] ?? 'null') . "\n";
echo "- company_id: " . ($result1['company_id'] ?? 'null') . "\n";

$test1Passed = $result1['client_id'] === '3' && $result1['company_id'] === '9';
echo "- Результат: " . ($test1Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

// Тест 2: Несуществующий контакт
$testProjectData2 = [
    'id' => '999998',
    'contactId' => '999999', // Несуществующий контакт
];

$result2 = testMapProjectData($testProjectData2, $mapping, $logger, $localStorage);

echo "Тест 2 - Несуществующий контакт ID=999999:\n";
echo "- client_id: " . ($result2['client_id'] ?? 'null') . "\n";
echo "- company_id: " . ($result2['company_id'] ?? 'null') . "\n";

$test2Passed = $result2['client_id'] === '999999' && $result2['company_id'] === null;
echo "- Результат: " . ($test2Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

if ($test1Passed && $test2Passed) {
    echo "=== ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО ===\n";
} else {
    echo "=== НЕКОТОРЫЕ ТЕСТЫ НЕ ПРОШЛИ ===\n";
}



