<?php
# -*- coding: utf-8 -*-

/**
 * Комплексный тест маппинга полей смарт-процессов
 *
 * Тестирует:
 * - mapProjectData для проектов
 * - mapChangeData для изменения данных
 * - mapDeleteData для удаления данных
 * - Корректность обработки различных типов данных
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';
$logger = new Logger($config);
$localStorage = new LocalStorage($logger, $config);

// Вспомогательная функция для извлечения contact ID
function extractContactId($rawValue)
{
    if (is_array($rawValue)) {
        return !empty($rawValue) ? (string)$rawValue[0] : null;
    }
    return !empty($rawValue) ? (string)$rawValue : null;
}

// Функция маппинга данных проектов
function mapProjectData($projectData, $mapping, $logger, $localStorage = null)
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

    // Обработка списочного поля "Тип запросов"
    $requestTypeRaw = $projectData[$mapping['request_type']] ?? null;
    $requestType = '';
    if (!empty($requestTypeRaw)) {
        if (is_array($requestTypeRaw)) {
            // Если массив, берем первый элемент или ID
            $requestType = $requestTypeRaw[0] ?? $requestTypeRaw['ID'] ?? '';
        } else {
            $requestType = (string)$requestTypeRaw;
        }
    }

    // Обработка множественного поля "Типы системы" (system_types)
    $systemTypesRaw = $projectData[$mapping['system_types']] ?? null;
    $systemTypes = [];
    if (!empty($systemTypesRaw)) {
        if (is_array($systemTypesRaw)) {
            // Если массив, обрабатываем каждый элемент
            foreach ($systemTypesRaw as $item) {
                if (is_array($item)) {
                    // Если элемент - объект, извлекаем ID
                    $itemId = $item['ID'] ?? $item['id'] ?? $item['VALUE'] ?? $item['value'] ?? null;
                    if ($itemId !== null) {
                        $systemTypes[] = (string)$itemId;
                    }
                } else {
                    // Если элемент - простое значение (ID)
                    $systemTypes[] = (string)$item;
                }
            }
        } else {
            // Если одиночное значение, преобразуем в массив
            $systemTypes[] = (string)$systemTypesRaw;
        }
    }

    // Обработка поля файла "Перечень оборудования" (множественное поле)
    $equipmentListRaw = $projectData[$mapping['equipment_list']] ?? null;
    $equipmentList = [];
    if (!empty($equipmentListRaw)) {
        if (is_array($equipmentListRaw)) {
            foreach ($equipmentListRaw as $file) {
                if (is_array($file)) {
                    $fileInfo = [
                        'id' => $file['id'] ?? $file['ID'] ?? null,
                        'name' => $file['name'] ?? $file['NAME'] ?? null,
                        'url' => $file['downloadUrl'] ?? $file['DOWNLOAD_URL'] ?? null,
                        'size' => $file['size'] ?? $file['SIZE'] ?? null
                    ];
                    if (!empty($fileInfo['id'])) {
                        $equipmentList[] = $fileInfo;
                    }
                } else {
                    // Если элемент - простое значение (ID)
                    $equipmentList[] = ['id' => (string)$file];
                }
            }
        } else {
            // Если одиночное значение, преобразуем в массив
            $equipmentList[] = ['id' => (string)$equipmentListRaw];
        }
    }

    return [
        'bitrix_id' => $projectId,
        'client_id' => $clientId,
        'company_id' => $companyId,
        'organization_name' => $projectData[$mapping['organization_name']] ?? '',
        'object_name' => $projectData[$mapping['object_name']] ?? '',
        'request_type' => $requestType,
        'system_types' => $systemTypes,
        'location' => $projectData[$mapping['location']] ?? '',
        'technical_description' => $projectData[$mapping['technical_description']] ?? '',
        'competitors' => $projectData[$mapping['competitors']] ?? '',
        'implementation_date' => $projectData[$mapping['implementation_date']] ?? '',
        'equipment_list' => $equipmentList,
        'marketing_discount' => $projectData[$mapping['marketing_discount']] ?? false,
        'status' => $projectData[$mapping['status']] ?? 'NEW',
    ];
}

// Функция маппинга данных изменения
function mapChangeData($changeData, $mapping, $logger)
{
    return [
        'contact_id' => extractContactId($changeData[$mapping['contact_id']] ?? null),
        'company_id' => extractContactId($changeData[$mapping['company_id']] ?? null),
        'manager_id' => extractContactId($changeData[$mapping['manager_id']] ?? null),
        'new_email' => $changeData[$mapping['new_email']] ?? '',
        'new_phone' => $changeData[$mapping['new_phone']] ?? '',
        'change_reason_personal' => $changeData[$mapping['change_reason_personal']] ?? '',
        'new_company_name' => $changeData[$mapping['new_company_name']] ?? '',
        'new_company_website' => $changeData[$mapping['new_company_website']] ?? '',
        'new_company_inn' => $changeData[$mapping['new_company_inn']] ?? '',
        'new_company_phone' => $changeData[$mapping['new_company_phone']] ?? '',
        'change_reason_company' => $changeData[$mapping['change_reason_company']] ?? '',
    ];
}

// Функция маппинга данных удаления
function mapDeleteData($deleteData, $mapping, $logger)
{
    return [
        'contact_id' => extractContactId($deleteData[$mapping['contact_id']] ?? null),
        'company_id' => extractContactId($deleteData[$mapping['company_id']] ?? null),
        'manager_id' => extractContactId($deleteData[$mapping['manager_id']] ?? null),
    ];
}

echo "=== КОМПЛЕКСНЫЙ ТЕСТ МАППИНГА ПОЛЕЙ СМАРТ-ПРОЦЕССОВ ===\n\n";

// === ТЕСТЫ ДЛЯ ПРОЕКТОВ ===
echo "1. ТЕСТЫ ДЛЯ ПРОЕКТОВ (smart_process)\n";
echo "====================================\n\n";

$projectMapping = $config['field_mapping']['smart_process'];

// Тест 1: Базовый маппинг с новыми UF кодами
$testProjectData1 = [
    'id' => '12345',
    'contactId' => ['3'], // Контакт с company=9
    'ufCrm7_1768130049371' => 'Тестовая организация',
    'ufCrm7_1768130056401' => 'Тестовый объект',
    'ufCrm7_1768130081539' => 'Тестовый запрос',
    'ufCrm7_1768130111325' => ['Тип системы 1', 'Тип системы 2'],
    'ufCrm7_1768130130483' => [
        ['id' => '123', 'name' => 'file1.pdf', 'downloadUrl' => 'http://example.com/file1.pdf', 'size' => 1024],
        ['id' => '456', 'name' => 'file2.pdf', 'downloadUrl' => 'http://example.com/file2.pdf', 'size' => 2048]
    ],
    'ufCrm7_1768130146776' => 'г. Москва, ул. Тестовая, д. 1',
    'ufCrm7_1768130163081' => 'Техническое описание проекта',
    'ufCrm7_1768130168777' => 'Конкуренты: Компания А, Компания Б',
    'ufCrm7_1768130177607' => '2024-12-31',
    'ufCrm7_1768130185822' => true,
    'stageId' => 'DT123_45:SUCCESS'
];

$result1 = mapProjectData($testProjectData1, $projectMapping, $logger, $localStorage);

echo "Тест 1.1 - Базовый маппинг:\n";
echo "- bitrix_id: {$result1['bitrix_id']} (ожидалось: 12345)\n";
echo "- client_id: {$result1['client_id']} (ожидалось: 3)\n";
echo "- company_id: {$result1['company_id']} (ожидалось: 9)\n";
echo "- organization_name: {$result1['organization_name']}\n";
echo "- object_name: {$result1['object_name']}\n";
echo "- request_type: {$result1['request_type']}\n";
echo "- system_types: " . implode(', ', $result1['system_types']) . "\n";
echo "- equipment_list: " . count($result1['equipment_list']) . " файлов\n";
echo "- location: {$result1['location']}\n";
echo "- technical_description: " . substr($result1['technical_description'], 0, 50) . "...\n";
echo "- competitors: " . substr($result1['competitors'], 0, 50) . "...\n";
echo "- implementation_date: {$result1['implementation_date']}\n";
echo "- marketing_discount: " . ($result1['marketing_discount'] ? 'true' : 'false') . "\n";
echo "- status: {$result1['status']}\n";

$test1_1Passed = (
    $result1['bitrix_id'] === '12345' &&
    $result1['client_id'] === '3' &&
    $result1['company_id'] === '9' &&
    $result1['organization_name'] === 'Тестовая организация' &&
    $result1['marketing_discount'] === true
);
echo "- Результат: " . ($test1_1Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

// Тест 1.2: Пустые значения
$testProjectData2 = [
    'id' => '67890',
    'contactId' => '999999', // Несуществующий контакт
];

$result2 = mapProjectData($testProjectData2, $projectMapping, $logger, $localStorage);

echo "Тест 1.2 - Пустые значения:\n";
echo "- bitrix_id: {$result2['bitrix_id']}\n";
echo "- client_id: {$result2['client_id']}\n";
echo "- company_id: {$result2['company_id']}\n";
echo "- Все остальные поля должны быть пустыми или по умолчанию\n";

$test1_2Passed = (
    $result2['bitrix_id'] === '67890' &&
    $result2['client_id'] === '999999' &&
    $result2['company_id'] === null &&
    empty($result2['organization_name']) &&
    $result2['marketing_discount'] === false
);
echo "- Результат: " . ($test1_2Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

// === ТЕСТЫ ДЛЯ ИЗМЕНЕНИЯ ДАННЫХ ===
echo "2. ТЕСТЫ ДЛЯ ИЗМЕНЕНИЯ ДАННЫХ (smart_process_change_data)\n";
echo "========================================================\n\n";

$changeMapping = $config['field_mapping']['smart_process_change_data'];

// Тест 2.1: Маппинг данных изменения
$testChangeData1 = [
    'contactId' => ['5'],
    'companyId' => ['12'],
    'assignedById' => ['8'],
    'ufCrm9_1768130256626' => 'newemail@example.com',
    'ufCrm9_1768130262174' => '+7 (999) 123-45-67',
    'ufCrm9_1768130269031' => 'Изменение контактных данных',
    'ufCrm9_1768130275443' => 'Новая компания ООО',
    'ufCrm9_1768130285153' => 'https://newcompany.com',
    'ufCrm9_1768130291668' => '123456789012',
    'ufCrm9_1768130300168' => '+7 (999) 987-65-43',
    'ufCrm9_1768130307424' => 'Изменение данных компании',
];

$result3 = mapChangeData($testChangeData1, $changeMapping, $logger);

echo "Тест 2.1 - Маппинг данных изменения:\n";
echo "- contact_id: {$result3['contact_id']}\n";
echo "- company_id: {$result3['company_id']}\n";
echo "- manager_id: {$result3['manager_id']}\n";
echo "- new_email: {$result3['new_email']}\n";
echo "- new_phone: {$result3['new_phone']}\n";
echo "- change_reason_personal: {$result3['change_reason_personal']}\n";
echo "- new_company_name: {$result3['new_company_name']}\n";
echo "- new_company_website: {$result3['new_company_website']}\n";
echo "- new_company_inn: {$result3['new_company_inn']}\n";
echo "- new_company_phone: {$result3['new_company_phone']}\n";
echo "- change_reason_company: {$result3['change_reason_company']}\n";

$test2_1Passed = (
    $result3['contact_id'] === '5' &&
    $result3['company_id'] === '12' &&
    $result3['manager_id'] === '8' &&
    $result3['new_email'] === 'newemail@example.com' &&
    $result3['new_company_inn'] === '123456789012'
);
echo "- Результат: " . ($test2_1Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

// === ТЕСТЫ ДЛЯ УДАЛЕНИЯ ДАННЫХ ===
echo "3. ТЕСТЫ ДЛЯ УДАЛЕНИЯ ДАННЫХ (smart_process_delete_data)\n";
echo "=======================================================\n\n";

$deleteMapping = $config['field_mapping']['smart_process_delete_data'];

// Тест 3.1: Маппинг данных удаления
$testDeleteData1 = [
    'contactId' => ['7'],
    'companyId' => ['15'],
    'assignedById' => ['10'],
];

$result4 = mapDeleteData($testDeleteData1, $deleteMapping, $logger);

echo "Тест 3.1 - Маппинг данных удаления:\n";
echo "- contact_id: {$result4['contact_id']}\n";
echo "- company_id: {$result4['company_id']}\n";
echo "- manager_id: {$result4['manager_id']}\n";

$test3_1Passed = (
    $result4['contact_id'] === '7' &&
    $result4['company_id'] === '15' &&
    $result4['manager_id'] === '10'
);
echo "- Результат: " . ($test3_1Passed ? "✓ ПРОШЕЛ" : "✗ НЕ ПРОШЕЛ") . "\n\n";

// === ИТОГИ ===
echo "=== РЕЗУЛЬТАТЫ ТЕСТИРОВАНИЯ ===\n\n";

$allTestsPassed = $test1_1Passed && $test1_2Passed && $test2_1Passed && $test3_1Passed;

echo "Пройдено тестов: " . ($test1_1Passed + $test1_2Passed + $test2_1Passed + $test3_1Passed) . "/4\n";

if ($allTestsPassed) {
    echo "🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!\n";
    echo "Маппинг полей смарт-процессов работает корректно с новыми UF кодами.\n";
} else {
    echo "⚠️  НЕКОТОРЫЕ ТЕСТЫ НЕ ПРОШЛИ!\n";
    echo "Необходимо проверить конфигурацию и функции маппинга.\n";
}

echo "\n=== РЕКОМЕНДАЦИИ ===\n";
echo "- Проверьте файл логов: src/logs/bitrix24_webhooks.log\n";
echo "- Убедитесь, что все UF коды в конфигурации соответствуют полям в Bitrix24\n";
echo "- При изменении структуры полей в Bitrix24 обновляйте конфигурацию\n";

?>


