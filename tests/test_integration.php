<?php
# -*- coding: utf-8 -*-

/**
 * Тестовый скрипт для проверки интеграции Битрикс24
 * Использует mock данные вместо реальных API вызовов
 */

require_once __DIR__ . '/../src/classes/Logger.php';

/**
 * Mock класс для Bitrix24API - симулирует работу без реальных API вызовов
 */
class MockBitrix24API
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
        $this->logger->info('Mock: Validating webhook request');
        $data = json_decode($body, true);
        return $data ?: false;
    }

    public function getEntityData($entityType, $entityId)
    {
        $this->logger->info('Mock: Getting entity data', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        // Возвращаем тестовые данные
        return [
            'result' => [
                'ID' => $entityId,
                'NAME' => 'Тестовый контакт',
                'LAST_NAME' => 'Тестовый',
                'EMAIL' => [['VALUE' => 'test@example.com']],
                'UF_CRM_CONTACT_LK_CLIENT' => 'Y'
            ]
        ];
    }

    public function getEntityTypeFromEvent($eventName)
    {
        $mapping = [
            'ONCRMCONTACTADD' => 'contact',
            'ONCRMCONTACTUPDATE' => 'contact',
            'ONCRMCONTACTDELETE' => 'contact',
            'ONCRMCOMPANYADD' => 'company',
            'ONCRMCOMPANYUPDATE' => 'company',
            'ONCRMCOMPANYDELETE' => 'company',
            'ONCRMDEALADD' => 'deal',
            'ONCRMDEALUPDATE' => 'deal',
            'ONCRM_DYNAMIC_ITEM_UPDATE' => 'smart_process'
        ];

        return $mapping[$eventName] ?? null;
    }
}

/**
 * Mock класс для LKAPI - симулирует работу без реальных API вызовов
 */
class MockLKAPI
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
        $this->logger->info('Mock: Creating LK', [
            'contact_id' => $contactData['ID'] ?? null,
            'company_id' => $companyData['ID'] ?? null
        ]);

        // Симулируем успешное создание
        return [
            'success' => true,
            'lk_id' => 'LK-' . rand(1000, 9999),
            'message' => 'Личный кабинет создан успешно'
        ];
    }

    public function syncContact($lkId, $contactData)
    {
        $this->logger->info('Mock: Syncing contact', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID'] ?? null
        ]);

        return [
            'success' => true,
            'message' => 'Контакт синхронизирован'
        ];
    }

    public function syncCompany($lkId, $companyData)
    {
        $this->logger->info('Mock: Syncing company', [
            'lk_id' => $lkId,
            'company_id' => $companyData['ID'] ?? null
        ]);

        return [
            'success' => true,
            'message' => 'Компания синхронизирована'
        ];
    }
}

/**
 * Тестовые данные для различных событий
 */
$testData = [
    'contact_update_with_lk' => [
        "event" => "ONCRMCONTACTUPDATE",
        "data" => [
            "FIELDS" => [
                "ID" => "12345",
                "NAME" => "Иван",
                "LAST_NAME" => "Петров",
                "EMAIL" => [
                    ["VALUE" => "ivan.petrov@example.com", "VALUE_TYPE" => "WORK"]
                ],
                "PHONE" => [
                    ["VALUE" => "+7 (999) 123-45-67", "VALUE_TYPE" => "WORK"]
                ],
                "UF_CRM_CONTACT_LK_CLIENT" => "Y"
            ]
        ],
        "auth" => [
            "application_token" => "test_token"
        ]
    ],

    'contact_create' => [
        "event" => "ONCRMCONTACTADD",
        "data" => [
            "FIELDS" => [
                "ID" => "12346",
                "NAME" => "Мария",
                "LAST_NAME" => "Иванова",
                "EMAIL" => [
                    ["VALUE" => "maria.ivanova@example.com", "VALUE_TYPE" => "WORK"]
                ],
                "UF_CRM_CONTACT_LK_CLIENT" => "N"
            ]
        ]
    ],

    'company_update' => [
        "event" => "ONCRMCOMPANYUPDATE",
        "data" => [
            "FIELDS" => [
                "ID" => "67890",
                "TITLE" => "ООО Ромашка",
                "EMAIL" => [
                    ["VALUE" => "info@romashka.ru", "VALUE_TYPE" => "WORK"]
                ],
                "UF_CRM_COMPANY_LK_CLIENT" => "LK-001"
            ]
        ]
    ],

    'smart_process_update' => [
        "event" => "ONCRM_DYNAMIC_ITEM_UPDATE",
        "data" => [
            "FIELDS" => [
                "ID" => "11111",
                "TITLE" => "Разработка сайта",
                "STAGE_ID" => "DT123_14:PREPARATION",
                "UF_CRM_PROJECT_LK_ID" => "LK-001"
            ],
            "ENTITY_TYPE" => "DT123",
            "ENTITY_TYPE_NAME" => "Проекты"
        ]
    ]
];

/**
 * Тестовая конфигурация
 */
$config = [
    'logging' => [
        'enabled' => true,
        'level' => 'DEBUG',
        'file' => __DIR__ . '/../src/logs/test_bitrix24_webhooks.log',
        'max_size' => 10 * 1024 * 1024, // 10MB
    ],
    'field_mapping' => [
        'contact' => [
            'lk_client_field' => 'UF_CRM_CONTACT_LK_CLIENT',
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL',
            'phone' => 'PHONE',
        ]
    ],
    'events' => [
        'max_retries' => 3,
        'retry_delays' => [5, 30, 300],
    ]
];

/**
 * Инициализация компонентов
 */
$logger = new Logger($config);
$bitrixAPI = new MockBitrix24API($config, $logger);
$lkAPI = new MockLKAPI($config, $logger);

/**
 * Копия функций обработки из основного обработчика для тестирования
 */
function processEvent($eventName, $webhookData, $bitrixAPI, $lkAPI, $logger)
{
    $entityType = $bitrixAPI->getEntityTypeFromEvent($eventName);
    $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

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

    // Используем mock данные вместо реального API
    $entityData = [
        'ID' => $entityId,
        'NAME' => 'Тестовый',
        'LAST_NAME' => 'Пользователь',
        'EMAIL' => [['VALUE' => 'test@example.com']],
        'UF_CRM_CONTACT_LK_CLIENT' => $webhookData['data']['FIELDS']['UF_CRM_CONTACT_LK_CLIENT'] ?? null
    ];

    // Определение действия
    $action = getActionFromEvent($eventName);

    switch ($action) {
        case 'create':
            return handleCreate($entityType, $entityData, $lkAPI, $logger);
        case 'update':
            return handleUpdate($entityType, $entityData, $lkAPI, $logger);
        case 'delete':
            return handleDelete($entityType, $entityData, $lkAPI, $logger);
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

function handleCreate($entityType, $entityData, $lkAPI, $logger)
{
    switch ($entityType) {
        case 'contact':
            $lkField = $entityData['UF_CRM_CONTACT_LK_CLIENT'] ?? null;
            if (!empty($lkField)) {
                $logger->info('Creating LK for new contact', ['contact_id' => $entityData['ID']]);
                return $lkAPI->createLK($entityData);
            }
            break;
    }
    return true;
}

function handleUpdate($entityType, $entityData, $lkAPI, $logger)
{
    switch ($entityType) {
        case 'contact':
            return handleContactUpdate($entityData, $lkAPI, $logger);
        case 'company':
            return handleCompanyUpdate($entityData, $lkAPI, $logger);
    }
    return true;
}

function handleContactUpdate($contactData, $lkAPI, $logger)
{
    $lkField = $contactData['UF_CRM_CONTACT_LK_CLIENT'] ?? null;

    if (!empty($lkField)) {
        $lkId = is_array($lkField) ? ($lkField[0] ?? null) : $lkField;

        if ($lkId) {
            $logger->info('Syncing contact update to existing LK', [
                'contact_id' => $contactData['ID'],
                'lk_id' => $lkId
            ]);
            return $lkAPI->syncContact($lkId, $contactData);
        } else {
            $logger->info('Creating new LK for contact', ['contact_id' => $contactData['ID']]);
            return $lkAPI->createLK($contactData);
        }
    }

    return true;
}

function handleCompanyUpdate($companyData, $lkAPI, $logger)
{
    $lkField = $companyData['UF_CRM_COMPANY_LK_CLIENT'] ?? null;

    if (!empty($lkField)) {
        $logger->info('Syncing company update to LK', [
            'company_id' => $companyData['ID'],
            'lk_id' => $lkField
        ]);
        return $lkAPI->syncCompany($lkField, $companyData);
    }

    return true;
}

function handleDelete($entityType, $entityData, $lkAPI, $logger)
{
    $logger->info('Entity deleted', [
        'type' => $entityType,
        'id' => $entityData['ID'] ?? 'unknown'
    ]);
    return true;
}

/**
 * Функция для запуска теста
 */
function runTest($testName, $testData, $bitrixAPI, $lkAPI, $logger)
{
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ЗАПУСК ТЕСТА: {$testName}\n";
    echo str_repeat("=", 60) . "\n";

    $logger->info("Starting test: {$testName}");

    try {
        $result = processEvent(
            $testData['event'],
            $testData,
            $bitrixAPI,
            $lkAPI,
            $logger
        );

        echo "РЕЗУЛЬТАТ: " . ($result ? "УСПЕХ ✓" : "ОШИБКА ✗") . "\n";

        if ($result && is_array($result)) {
            echo "ДЕТАЛИ: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        }

        return $result;

    } catch (Exception $e) {
        echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
        $logger->error("Test exception", [
            'test' => $testName,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Запуск всех тестов
 */
echo "НАЧАЛО ТЕСТИРОВАНИЯ ИНТЕГРАЦИИ БИТРИКС24\n";
echo str_repeat("=", 60) . "\n";

$results = [];

foreach ($testData as $testName => $data) {
    $results[$testName] = runTest($testName, $data, $bitrixAPI, $lkAPI, $logger);
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "ИТОГИ ТЕСТИРОВАНИЯ:\n";
echo str_repeat("=", 60) . "\n";

$passed = 0;
$total = count($results);

foreach ($results as $testName => $result) {
    $status = $result ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН";
    echo sprintf("%-25s: %s\n", ucfirst(str_replace('_', ' ', $testName)), $status);
    if ($result) $passed++;
}

echo "\nПРОЙДЕНО: {$passed}/{$total} тестов\n";

if ($passed === $total) {
    echo "✓ ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
} else {
    echo "⚠ НЕКОТОРЫЕ ТЕСТЫ ПРОВАЛЕНЫ\n";
}

echo "\nЛоги сохранены в: " . $config['logging']['file'] . "\n";

?>
