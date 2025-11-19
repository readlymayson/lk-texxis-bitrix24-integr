<?php
# -*- coding: utf-8 -*-

/**
 * Основной обработчик вебхуков от Битрикс24
 *
 * Принимает и обрабатывает события от Битрикс24:
 * - ONCRMCONTACTUPDATE - изменение контакта
 * - ONCRMCONTACTADD - создание контакта
 * - ONCRMCONTACTDELETE - удаление контакта
 * - ONCRMCOMPANYUPDATE - изменение компании
 * - ONCRMCOMPANYADD - создание компании
 * - ONCRMCOMPANYDELETE - удаление компании
 * - ONCRMDEALUPDATE - изменение сделки
 * - ONCRM_DYNAMIC_ITEM_UPDATE - изменение смарт-процесса
 */

// Подключение автозагрузки классов
require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

// Загрузка конфигурации (включает загрузку .env)
$config = require_once __DIR__ . '/../config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger);

try {
    // Получение тела запроса ДО валидации
    $rawBody = file_get_contents('php://input');

    // Получение всех заголовков
    $headers = getRequestHeaders();

    // Детальное логирование входящего webhook запроса
    $logger->info('=== WEBHOOK REQUEST RECEIVED ===', [
        'timestamp' => date('Y-m-d H:i:s'),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN',
        'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'UNKNOWN',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
        'remote_port' => $_SERVER['REMOTE_PORT'] ?? 'UNKNOWN',
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'UNKNOWN',
        'request_time' => $_SERVER['REQUEST_TIME'] ?? 'UNKNOWN',
        'headers_count' => count($headers),
        'body_size' => strlen($rawBody),
        'body_preview' => strlen($rawBody) > 200 ? substr($rawBody, 0, 200) . '...' : $rawBody
    ]);

    // Логирование всех HTTP заголовков
    $logger->debug('Webhook headers', ['headers' => $headers]);

    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logger->warning('Invalid request method', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'expected' => 'POST'
        ]);
        sendResponse(405, ['error' => 'Method not allowed. Use POST.']);
        exit;
    }

    // Проверка тела запроса
    if (empty($rawBody)) {
        $logger->warning('Empty request body received', [
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
            'headers' => $headers
        ]);
        sendResponse(400, ['error' => 'Empty request body']);
        exit;
    }

    // Валидация webhook запроса
    $webhookData = $bitrixAPI->validateWebhookRequest($headers, $rawBody);
    if ($webhookData === false) {
        $logger->error('Webhook validation failed', [
            'raw_body' => $rawBody,
            'headers' => $headers
        ]);
        sendResponse(400, ['error' => 'Invalid webhook request']);
        exit;
    }

    // Детальное логирование валидных данных от Битрикс24
    $logger->info('=== WEBHOOK DATA VALIDATED ===', [
        'event' => $webhookData['event'] ?? 'UNKNOWN',
        'event_type' => getWebhookEventType($webhookData['event'] ?? ''),
        'timestamp' => $webhookData['ts'] ?? 'UNKNOWN',
        'auth_token' => $webhookData['auth']['application_token'] ?? 'UNKNOWN',
        'data_keys' => array_keys($webhookData['data'] ?? []),
        'entity_id' => $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? 'UNKNOWN',
        'webhook_data_size' => strlen(json_encode($webhookData))
    ]);

    // Логирование полных данных webhook (только для отладки)
    $logger->debug('Full webhook data from Bitrix24', [
        'webhook_data' => $webhookData
    ]);

    // Определение типа события и обработка
    $eventName = $webhookData['event'] ?? '';
    $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

    if (empty($eventName)) {
        $logger->error('Missing event name in webhook data', [
            'webhook_data_keys' => array_keys($webhookData)
        ]);
        sendResponse(400, ['error' => 'Missing event name']);
        exit;
    }

    // Логирование начала обработки события
    $logger->info('=== STARTING EVENT PROCESSING ===', [
        'event_name' => $eventName,
        'event_type' => getWebhookEventType($eventName),
        'action' => getActionFromEvent($eventName),
        'entity_id' => $entityId,
        'processing_start_time' => date('Y-m-d H:i:s')
    ]);

    // Обработка события с повторными попытками
    $result = processEventWithRetry($eventName, $webhookData, $config, $logger, $bitrixAPI, $localStorage);

    // Логирование завершения обработки события
    $processingEndTime = date('Y-m-d H:i:s');
    $processingDuration = time() - strtotime($webhookData['ts'] ?? 'now');

    if ($result) {
        $logger->info('=== EVENT PROCESSING COMPLETED SUCCESSFULLY ===', [
            'event_name' => $eventName,
            'event_type' => getWebhookEventType($eventName),
            'entity_id' => $entityId,
            'processing_end_time' => $processingEndTime,
            'processing_duration_seconds' => $processingDuration,
            'result' => 'SUCCESS'
        ]);
        sendResponse(200, ['status' => 'success', 'event' => $eventName]);
    } else {
        $logger->error('=== EVENT PROCESSING FAILED ===', [
            'event_name' => $eventName,
            'event_type' => getWebhookEventType($eventName),
            'entity_id' => $entityId,
            'processing_end_time' => $processingEndTime,
            'processing_duration_seconds' => $processingDuration,
            'result' => 'FAILED'
        ]);
        sendResponse(500, ['error' => 'Processing failed', 'event' => $eventName]);
    }

} catch (Exception $e) {
    $logger->error('Critical error in webhook handler', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    sendResponse(500, ['error' => 'Internal server error']);
}

/**
 * Обработка события с механизмом повторных попыток
 */
function processEventWithRetry($eventName, $webhookData, $config, $logger, $bitrixAPI, $localStorage)
{
    $maxRetries = $config['events']['max_retries'];
    $retryDelays = $config['events']['retry_delays'];

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        try {
            $logger->debug('Processing event attempt', [
                'event' => $eventName,
                'attempt' => $attempt + 1,
                'max_attempts' => $maxRetries + 1
            ]);

            $result = processEvent($eventName, $webhookData, $bitrixAPI, $localStorage, $logger, $config);

            if ($result) {
                return true;
            }

            // Если это не последняя попытка, ждем перед следующей
            if ($attempt < $maxRetries) {
                $delay = $retryDelays[$attempt] ?? end($retryDelays);
                $logger->info('Retrying after delay', [
                    'attempt' => $attempt + 1,
                    'delay' => $delay,
                    'event' => $eventName
                ]);
                sleep($delay);
            }

        } catch (Exception $e) {
            $logger->error('Exception during event processing', [
                'event' => $eventName,
                'attempt' => $attempt + 1,
                'error' => $e->getMessage()
            ]);

            // Для исключений не делаем повторные попытки
            break;
        }
    }

    return false;
}

/**
 * Проверка допустимого значения поля ЛК клиента
 */
function isValidLKClientValue($entityType, $entityData, $config, $logger)
{
    // Проверяем, есть ли настройки поля ЛК клиента для этого типа сущности
    if (!isset($config['field_mapping'][$entityType]['lk_client_field'])) {
        $logger->debug('LK client field not configured for entity type', [
            'entity_type' => $entityType
        ]);
        return false;
    }

    $fieldName = $config['field_mapping'][$entityType]['lk_client_field'];
    $allowedValues = $config['field_mapping'][$entityType]['lk_client_values'] ?? [];

    $fieldValue = $entityData[$fieldName] ?? null;

    if (empty($fieldValue)) {
        $logger->debug('LK client field is empty or not set', [
            'entity_type' => $entityType,
            'field_name' => $fieldName,
            'field_value' => $fieldValue
        ]);
        return false;
    }

    $isValid = in_array($fieldValue, $allowedValues, true);

    $logger->debug('LK client field validation', [
        'entity_type' => $entityType,
        'field_name' => $fieldName,
        'field_value' => $fieldValue,
        'allowed_values' => $allowedValues,
        'is_valid' => $isValid
    ]);

    return $isValid;
}

/**
 * Основная логика обработки события
 */
function processEvent($eventName, $webhookData, $bitrixAPI, $localStorage, $logger, $config)
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

    // Получение полных данных сущности из Битрикс24
    $entityData = $bitrixAPI->getEntityData($entityType, $entityId);
    if (!$entityData || !isset($entityData['result'])) {
        $logger->error('Failed to get entity data from Bitrix24', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
        return false;
    }

    $entityData = $entityData['result'];

    // Определение действия на основе события
    $action = getActionFromEvent($eventName);

    switch ($action) {
        case 'create':
            return handleCreate($entityType, $entityData, $localStorage, $logger, $config);

        case 'update':
            return handleUpdate($entityType, $entityData, $localStorage, $bitrixAPI, $logger, $config);

        case 'delete':
            return handleDelete($entityType, $entityData, $localStorage, $logger);

        default:
            $logger->warning('Unknown action for event', [
                'event' => $eventName,
                'action' => $action
            ]);
            return true; // Не считаем неизвестное действие ошибкой
    }
}

/**
 * Обработка создания сущности
 */
function handleCreate($entityType, $entityData, $localStorage, $logger, $config)
{
    switch ($entityType) {
        case 'contact':
            // Проверяем, нужно ли создавать ЛК для этого контакта
            if (isValidLKClientValue('contact', $entityData, $config, $logger)) {
                $logger->info('Creating LK for new contact with valid LK client field', ['contact_id' => $entityData['ID']]);
                return $localStorage->createLK($entityData);
            } else {
                $logger->info('Skipping LK creation for new contact - invalid LK client field value', ['contact_id' => $entityData['ID']]);
            }
            break;

        case 'company':
            // Компании могут требовать создания ЛК отдельно
            $logger->info('New company created', ['company_id' => $entityData['ID']]);
            break;

        case 'smart_process':
            // Обработка создания смарт-процесса (проекта)
            $logger->info('New smart process created', ['process_id' => $entityData['ID']]);
            break;
    }

    return true;
}

/**
 * Обработка обновления сущности
 */
function handleUpdate($entityType, $entityData, $localStorage, $bitrixAPI, $logger, $config)
{
    switch ($entityType) {
        case 'contact':
            return handleContactUpdate($entityData, $localStorage, $bitrixAPI, $logger, $config);

        case 'company':
            return handleCompanyUpdate($entityData, $localStorage, $bitrixAPI, $logger, $config);

        case 'deal':
            return handleDealUpdate($entityData, $localStorage, $logger, $config);

        case 'smart_process':
            return handleSmartProcessUpdate($entityData, $localStorage, $logger, $config);
    }

    return true;
}

/**
 * Обработка обновления контакта
 */
function handleContactUpdate($contactData, $localStorage, $bitrixAPI, $logger, $config)
{
    $contactId = $contactData['ID'];

    // contactData уже содержит полные данные из Bitrix24 API (из processEventWithRetry)
    $logger->info('Processing contact update with full data from Bitrix24', [
        'contact_id' => $contactId,
        'name' => $contactData['NAME'] ?? 'N/A',
        'email_count' => count($contactData['EMAIL'] ?? []),
        'phone_count' => count($contactData['PHONE'] ?? [])
    ]);

    // Проверяем, существует ли контакт в локальном хранилище
    $existingContact = $localStorage->getContact($contactId);

    if ($existingContact) {
        // Контакт найден - проверяем, допустимо ли значение поля ЛК перед обновлением
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Updating existing contact with full data from Bitrix24 - valid LK field', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            return $localStorage->syncContact($existingContact['id'], $contactData);
        } else {
            $logger->info('Skipping contact update - invalid LK client field value', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            return true; // Не считаем это ошибкой, просто пропускаем обновление
        }
    } else {
        // Контакт не найден - проверяем, нужно ли создавать ЛК
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Creating new LK with full data from Bitrix24 - valid LK client field', ['contact_id' => $contactId]);
            return $localStorage->createLK($contactData);
        } else {
            $logger->info('Skipping LK creation for contact update - invalid LK client field value', ['contact_id' => $contactId]);
            return true; // Не считаем это ошибкой, просто пропускаем создание
        }
    }
}

/**
 * Обработка обновления компании
 */
function handleCompanyUpdate($companyData, $localStorage, $bitrixAPI, $logger, $config)
{
    $companyId = $companyData['ID'];

    // Получаем полные данные компании из Bitrix24 API через crm.company.get
    $logger->info('Fetching full company data from Bitrix24 API via crm.company.get', ['company_id' => $companyId]);

    try {
        $fullCompanyData = $bitrixAPI->getEntityData('company', $companyId);

        if (!$fullCompanyData) {
            $logger->warning('Failed to fetch company data from Bitrix24 API', ['company_id' => $companyId]);
            return false;
        }

        $logger->info('Successfully fetched company data from Bitrix24', [
            'company_id' => $companyId,
            'title' => $fullCompanyData['TITLE'] ?? 'N/A'
        ]);

        // Синхронизируем данные компании
        $logger->info('Syncing company data to local storage', ['company_id' => $companyId]);
        return $localStorage->syncCompany(null, $fullCompanyData);

    } catch (Exception $e) {
        $logger->error('Error fetching company data from Bitrix24 API', [
            'company_id' => $companyId,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}

/**
 * Обработка обновления сделки
 */
function handleDealUpdate($dealData, $localStorage, $logger, $config)
{
    // Логика обработки изменений сделок
    // Может включать обновление статусов проектов в ЛК
    $logger->info('Deal updated', [
        'deal_id' => $dealData['ID'],
        'stage' => $dealData['STAGE_ID'] ?? 'unknown'
    ]);

    // Сохраняем данные сделки локально
    return $localStorage->addDeal($dealData);
}

/**
 * Обработка обновления смарт-процесса
 */
function handleSmartProcessUpdate($processData, $localStorage, $logger, $config)
{
    // Синхронизация данных проекта в ЛК
    $logger->info('Smart process updated', [
        'process_id' => $processData['ID'],
        'entity_type' => $processData['ENTITY_TYPE'] ?? 'unknown'
    ]);

    // Определяем, связан ли этот процесс с каким-то ЛК
    // Логика зависит от структуры данных проекта

    return true;
}

/**
 * Обработка удаления сущности
 */
function handleDelete($entityType, $entityData, $localStorage, $logger)
{
    switch ($entityType) {
        case 'contact':
            // Обработка удаления контакта
            $logger->info('Contact deleted', ['contact_id' => $entityData['ID'] ?? 'unknown']);
            // Возможно, требуется деактивация ЛК
            break;

        case 'company':
            $logger->info('Company deleted', ['company_id' => $entityData['ID'] ?? 'unknown']);
            break;
    }

    return true;
}

/**
 * Определение типа события webhook
 */
function getWebhookEventType($eventName)
{
    $eventTypes = [
        'CONTACT' => 'contact',
        'COMPANY' => 'company',
        'DEAL' => 'deal',
        'DYNAMIC_ITEM' => 'smart_process'
    ];

    foreach ($eventTypes as $type => $entity) {
        if (str_contains($eventName, $type)) {
            return $entity;
        }
    }

    return 'unknown';
}

/**
 * Определение действия из названия события
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

/**
 * Получение заголовков запроса
 */
function getRequestHeaders()
{
    $headers = [];

    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $headerName = str_replace('HTTP_', '', $key);
            $headerName = str_replace('_', '-', $headerName);
            $headers[strtolower($headerName)] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'USER_AGENT'])) {
            $headerName = str_replace('_', '-', $key);
            $headers[strtolower($headerName)] = $value;
        }
    }

    return $headers;
}

/**
 * Отправка HTTP ответа
 */
function sendResponse($statusCode, $data)
{
    http_response_code($statusCode);

    header('Content-Type: application/json; charset=utf-8');
    header('X-Powered-By: Bitrix24-Webhook-Handler');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

