<?php
# -*- coding: utf-8 -*-

/**
 * Тесты для edge cases и обработки ошибок
 */

require_once __DIR__ . '/../src/classes/Logger.php';

/**
 * Mock классы для тестирования edge cases
 */
class MockBitrix24APIEdge
{
    private $config;
    private $logger;

    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function validateWebhookRequest($headers, $body)
    {
        return json_decode($body, true) ?: false;
    }

    public function getEntityTypeFromEvent($eventName)
    {
        $mapping = [
            'ONCRMCONTACTUPDATE' => 'contact',
            'ONCRMCONTACTADD' => 'contact',
            'ONCRMCONTACTDELETE' => 'contact',
            'ONCRMCOMPANYUPDATE' => 'company',
            'ONCRMCOMPANYADD' => 'company',
            'ONCRMCOMPANYDELETE' => 'company',
            'ONCRMDEALUPDATE' => 'deal',
            'ONCRMDEALADD' => 'deal',
            'ONCRM_DYNAMIC_ITEM_UPDATE' => 'smart_process',
            'UNKNOWN_EVENT' => null, // Для тестирования неизвестных событий
        ];

        return $mapping[$eventName] ?? null;
    }
}

class MockLKAPIEdge
{
    private $config;
    private $logger;

    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function createLK($contactData, $companyData = null)
    {
        // Симулируем ошибку для тестирования
        if (isset($contactData['simulate_error'])) {
            return false;
        }
        return ['success' => true, 'lk_id' => 'LK-' . rand(1000, 9999)];
    }

    public function syncContact($lkId, $contactData)
    {
        if (isset($contactData['simulate_error'])) {
            return false;
        }
        return ['success' => true];
    }

    public function syncCompany($lkId, $companyData)
    {
        if (isset($companyData['simulate_error'])) {
            return false;
        }
        return ['success' => true];
    }
}

/**
 * Тестовые данные для edge cases
 */
$edgeCaseTests = [
    'missing_event' => [
        'name' => 'Отсутствующее событие',
        'data' => [
            'data' => ['FIELDS' => ['ID' => '123']]
        ],
        'expected' => false
    ],

    'unknown_event' => [
        'name' => 'Неизвестное событие',
        'data' => [
            'event' => 'UNKNOWN_EVENT',
            'data' => ['FIELDS' => ['ID' => '123']]
        ],
        'expected' => false // Неизвестное событие приводит к ошибке определения типа
    ],

    'missing_entity_id' => [
        'name' => 'Отсутствующий ID сущности',
        'data' => [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => ['FIELDS' => ['NAME' => 'Test']]
        ],
        'expected' => false
    ],

    'empty_fields' => [
        'name' => 'Пустые поля',
        'data' => [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => ['FIELDS' => []]
        ],
        'expected' => false
    ],

    'api_error_simulation' => [
        'name' => 'Симуляция ошибки API ЛК',
        'data' => [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => [
                'FIELDS' => [
                    'ID' => '12345',
                    'NAME' => 'Test',
                    'UF_CRM_CONTACT_LK_CLIENT' => 'Y',
                    'simulate_error' => true // Специальный флаг для симуляции ошибки
                ]
            ]
        ],
        'expected' => false
    ],

    'malformed_data' => [
        'name' => 'Некорректные данные',
        'data' => [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => 'not an array'
        ],
        'expected' => false
    ],

    'nested_entity_id' => [
        'name' => 'Вложенный ID сущности',
        'data' => [
            'event' => 'ONCRM_DYNAMIC_ITEM_UPDATE',
            'data' => [
                'FIELDS' => ['ID' => '999'],
                'ENTITY_TYPE' => 'DT123'
            ],
            'entity_id' => '999' // Альтернативный способ передачи ID
        ],
        'expected' => true
    ]
];

/**
 * Тестовая конфигурация
 */
$config = [
    'logging' => [
        'enabled' => true,
        'level' => 'DEBUG',
        'file' => __DIR__ . '/../src/logs/test_edge_cases.log',
        'max_size' => 10 * 1024 * 1024,
    ]
];

/**
 * Инициализация компонентов
 */
$logger = new Logger($config);
$bitrixAPI = new MockBitrix24APIEdge($config, $logger);
$lkAPI = new MockLKAPIEdge($config, $logger);

/**
 * Функция обработки события (упрощенная копия из основного обработчика)
 */
function processEvent($eventName, $webhookData, $bitrixAPI, $lkAPI, $logger)
{
    $entityType = $bitrixAPI->getEntityTypeFromEvent($eventName);
    $entityId = $webhookData['data']['FIELDS']['ID'] ??
                $webhookData['data']['ID'] ??
                $webhookData['entity_id'] ??
                null;

    if (!$entityType || !$entityId) {
        $logger->error('Cannot determine entity type or ID', [
            'event' => $eventName,
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
        return false;
    }

    $logger->info('Processing event', [
        'event' => $eventName,
        'entity_type' => $entityType,
        'entity_id' => $entityId
    ]);

    // Mock данные для тестирования
    $entityData = $webhookData['data']['FIELDS'] ?? [];

    $action = getActionFromEvent($eventName);

    switch ($action) {
        case 'create':
        case 'update':
            if ($entityType === 'contact') {
                $lkField = $entityData['UF_CRM_CONTACT_LK_CLIENT'] ?? null;
                if (!empty($lkField)) {
                    $lkId = is_array($lkField) ? ($lkField[0] ?? null) : $lkField;
                    if ($lkId) {
                        return $lkAPI->syncContact($lkId, $entityData);
                    } else {
                        return $lkAPI->createLK($entityData);
                    }
                }
            }
            return true;
        case 'delete':
            $logger->info('Entity deleted', ['type' => $entityType, 'id' => $entityId]);
            return true;
        default:
            return true;
    }
}

function getActionFromEvent($eventName)
{
    $actions = [
        'ADD' => 'create',
        'UPDATE' => 'update',
        'DELETE' => 'delete'
    ];

    foreach ($actions as $suffix => $action) {
        if (str_ends_with($eventName, $suffix)) {
            return $action;
        }
    }

    return 'unknown';
}

/**
 * Функция для запуска edge case теста
 */
function runEdgeCaseTest($testName, $testData, $bitrixAPI, $lkAPI, $logger)
{
    echo "\n" . str_repeat("-", 50) . "\n";
    echo "EDGE CASE ТЕСТ: {$testData['name']}\n";
    echo str_repeat("-", 50) . "\n";

    $logger->info("Starting edge case test: {$testName}");

    try {
        $result = processEvent(
            $testData['data']['event'] ?? '',
            $testData['data'],
            $bitrixAPI,
            $lkAPI,
            $logger
        );

        $success = ($result !== false) === $testData['expected'];

        echo "ОЖИДАЕТСЯ: " . ($testData['expected'] ? "УСПЕХ" : "ОШИБКА") . "\n";
        echo "ПОЛУЧЕНО: " . ($result !== false ? "УСПЕХ" : "ОШИБКА") . "\n";
        echo "РЕЗУЛЬТАТ: " . ($success ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН") . "\n";

        return $success;

    } catch (Exception $e) {
        $success = !$testData['expected']; // Если ожидалась ошибка, то исключение - это успех

        echo "ОЖИДАЕТСЯ: " . ($testData['expected'] ? "УСПЕХ" : "ОШИБКА") . "\n";
        echo "ПОЛУЧЕНО: ИСКЛЮЧЕНИЕ (" . $e->getMessage() . ")\n";
        echo "РЕЗУЛЬТАТ: " . ($success ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН") . "\n";

        return $success;
    }
}

/**
 * Запуск всех edge case тестов
 */
echo "НАЧАЛО ТЕСТИРОВАНИЯ EDGE CASES\n";
echo str_repeat("=", 60) . "\n";

$results = [];
$passed = 0;
$total = count($edgeCaseTests);

foreach ($edgeCaseTests as $testName => $testData) {
    $results[$testName] = runEdgeCaseTest($testName, $testData, $bitrixAPI, $lkAPI, $logger);
    if ($results[$testName]) $passed++;
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "ИТОГИ ТЕСТИРОВАНИЯ EDGE CASES:\n";
echo str_repeat("=", 60) . "\n";

foreach ($results as $testName => $result) {
    $displayName = $edgeCaseTests[$testName]['name'];
    $status = $result ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН";
    echo sprintf("%-25s: %s\n", $displayName, $status);
}

echo "\nПРОЙДЕНО: {$passed}/{$total} edge case тестов\n";

if ($passed === $total) {
    echo "✓ ВСЕ EDGE CASE ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
} else {
    echo "⚠ НЕКОТОРЫЕ EDGE CASE ТЕСТЫ ПРОВАЛЕНЫ\n";
}

echo "\nЛоги сохранены в: " . $config['logging']['file'] . "\n";

?>
