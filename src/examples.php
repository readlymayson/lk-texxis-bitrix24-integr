<?php
# -*- coding: utf-8 -*-

/**
 * Примеры обработки различных событий от Битрикс24
 *
 * Этот файл содержит примеры JSON данных, которые отправляет Битрикс24
 * при различных событиях, а также примеры обработки этих данных.
 */

require_once __DIR__ . '/classes/Logger.php';
require_once __DIR__ . '/classes/Bitrix24API.php';
require_once __DIR__ . '/classes/LKAPI.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$lkAPI = new LKAPI($config, $logger);

/**
 * ПРИМЕР 1: Изменение контакта с установкой поля "ЛК клиента"
 * Сценарий: Менеджер в Битрикс24 устанавливает флаг "Создать ЛК" для контакта
 */
$contactUpdateExample = [
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
            "UF_CRM_CONTACT_LK_CLIENT" => "Y" // Поле установлено - нужно создать ЛК
        ]
    ],
    "auth" => [
        "application_token" => "your_app_token"
    ]
];

/**
 * ПРИМЕР 2: Создание нового контакта
 */
$contactCreateExample = [
    "event" => "ONCRMCONTACTADD",
    "data" => [
        "FIELDS" => [
            "ID" => "12346",
            "NAME" => "Мария",
            "LAST_NAME" => "Иванова",
            "EMAIL" => [
                ["VALUE" => "maria.ivanova@example.com", "VALUE_TYPE" => "WORK"]
            ],
            "PHONE" => [
                ["VALUE" => "+7 (999) 765-43-21", "VALUE_TYPE" => "WORK"]
            ],
            "UF_CRM_CONTACT_LK_CLIENT" => "N" // ЛК не нужен
        ]
    ]
];

/**
 * ПРИМЕР 3: Изменение компании
 */
$companyUpdateExample = [
    "event" => "ONCRMCOMPANYUPDATE",
    "data" => [
        "FIELDS" => [
            "ID" => "67890",
            "TITLE" => "ООО Ромашка",
            "EMAIL" => [
                ["VALUE" => "info@romashka.ru", "VALUE_TYPE" => "WORK"]
            ],
            "PHONE" => [
                ["VALUE" => "+7 (495) 123-45-67", "VALUE_TYPE" => "WORK"]
            ]
            // Компании не имеют поля личного кабинета
        ]
    ]
];

/**
 * ПРИМЕР 4: Изменение смарт-процесса (проекта)
 */
$smartProcessUpdateExample = [
    "event" => "ONCRM_DYNAMIC_ITEM_UPDATE",
    "data" => [
        "FIELDS" => [
            "ID" => "11111",
            "TITLE" => "Разработка сайта",
            "DESCRIPTION" => "Создание корпоративного сайта",
            "STAGE_ID" => "DT123_14:PREPARATION", // Статус проекта
            "UF_CRM_PROJECT_LK_ID" => "LK-001", // Связь с ЛК
            "ASSIGNED_BY_ID" => "1", // Ответственный менеджер
            "DATE_CREATE" => "2025-01-15T10:00:00+03:00",
            "DATE_MODIFY" => "2025-01-18T14:30:00+03:00"
        ],
        "ENTITY_TYPE" => "DT123", // ID смарт-процесса
        "ENTITY_TYPE_NAME" => "Проекты"
    ]
];

/**
 * ПРИМЕР 5: Изменение сделки
 */
$dealUpdateExample = [
    "event" => "ONCRMDEALUPDATE",
    "data" => [
        "FIELDS" => [
            "ID" => "22222",
            "TITLE" => "Продажа услуги",
            "STAGE_ID" => "C1:WON", // Стадия "Выиграна"
            "CONTACT_ID" => "12345", // Связанный контакт
            "COMPANY_ID" => "67890", // Связанная компания
            "UF_CRM_DEAL_LK_CLIENT" => "LK-001", // Связь с ЛК
            "OPPORTUNITY" => "150000", // Сумма
            "CURRENCY_ID" => "RUB"
        ]
    ]
];

/**
 * Функция для тестирования обработки примера
 */
function testExample($exampleName, $exampleData, $bitrixAPI, $lkAPI, $logger)
{
    echo "\n=== Тестирование примера: {$exampleName} ===\n";

    $logger->info("Testing example: {$exampleName}");

    try {
        // Симуляция обработки события
        $result = processEvent(
            $exampleData['event'],
            $exampleData,
            $bitrixAPI,
            $lkAPI,
            $logger
        );

        echo "Результат: " . ($result ? "УСПЕХ" : "ОШИБКА") . "\n";

    } catch (Exception $e) {
        echo "ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
        $logger->error("Test exception", ['example' => $exampleName, 'error' => $e->getMessage()]);
    }
}

/**
 * Основная логика обработки события (копия из bitrix24.php для тестирования)
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

    // В примере используем тестовые данные вместо реального API
    $entityData = getTestEntityData($entityType, $entityId, $webhookData);

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

/**
 * Получение тестовых данных сущности
 */
function getTestEntityData($entityType, $entityId, $webhookData)
{
    // В реальном коде здесь был бы вызов $bitrixAPI->getEntityData()
    // Для примера возвращаем данные из webhook
    return $webhookData['data']['FIELDS'] ?? [];
}

/**
 * Вспомогательные функции (копии из основного обработчика)
 */
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
                // В тесте не делаем реальный вызов API
                return true;
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
        } else {
            $logger->info('Creating new LK for contact', ['contact_id' => $contactData['ID']]);
        }
    }

    return true;
}

function handleCompanyUpdate($companyData, $lkAPI, $logger)
{
    // Компании не имеют поля личного кабинета, синхронизируем всегда
    $logger->info('Syncing company update to LK', [
        'company_id' => $companyData['ID']
    ]);
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

// Запуск тестов (раскомментируйте для тестирования)
// testExample('Contact Update with LK', $contactUpdateExample, $bitrixAPI, $lkAPI, $logger);
// testExample('Contact Create', $contactCreateExample, $bitrixAPI, $lkAPI, $logger);
// testExample('Company Update', $companyUpdateExample, $bitrixAPI, $lkAPI, $logger);

echo "Примеры загружены. Для запуска тестов раскомментируйте вызовы testExample().\n";

