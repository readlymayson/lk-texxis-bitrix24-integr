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
require_once __DIR__ . '/../classes/LKAPI.php';

// Загрузка конфигурации (включает загрузку .env)
$config = require_once __DIR__ . '/../config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$lkAPI = new LKAPI($config, $logger);

try {
    // Логирование входящего запроса
    $logger->info('Received webhook request', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN'
    ]);

    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logger->warning('Invalid request method', ['method' => $_SERVER['REQUEST_METHOD']]);
        sendResponse(405, ['error' => 'Method not allowed. Use POST.']);
        exit;
    }

    // Получение тела запроса
    $rawBody = file_get_contents('php://input');
    if (empty($rawBody)) {
        $logger->warning('Empty request body');
        sendResponse(400, ['error' => 'Empty request body']);
        exit;
    }

    // Получение заголовков
    $headers = getRequestHeaders();

    // Валидация webhook запроса
    $webhookData = $bitrixAPI->validateWebhookRequest($headers, $rawBody);
    if ($webhookData === false) {
        $logger->error('Webhook validation failed');
        sendResponse(400, ['error' => 'Invalid webhook request']);
        exit;
    }

    $logger->info('Webhook data received', [
        'event' => $webhookData['event'] ?? 'UNKNOWN',
        'data_keys' => array_keys($webhookData)
    ]);

    // Определение типа события и обработка
    $eventName = $webhookData['event'] ?? '';
    $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

    if (empty($eventName)) {
        $logger->error('Missing event name in webhook data');
        sendResponse(400, ['error' => 'Missing event name']);
        exit;
    }

    // Обработка события с повторными попытками
    $result = processEventWithRetry($eventName, $webhookData, $config, $logger, $bitrixAPI, $lkAPI);

    if ($result) {
        $logger->info('Webhook processed successfully', ['event' => $eventName]);
        sendResponse(200, ['status' => 'success', 'event' => $eventName]);
    } else {
        $logger->error('Webhook processing failed', ['event' => $eventName]);
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
function processEventWithRetry($eventName, $webhookData, $config, $logger, $bitrixAPI, $lkAPI)
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

            $result = processEvent($eventName, $webhookData, $bitrixAPI, $lkAPI, $logger, $config);

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
 * Основная логика обработки события
 */
function processEvent($eventName, $webhookData, $bitrixAPI, $lkAPI, $logger, $config)
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
            return handleCreate($entityType, $entityData, $lkAPI, $logger, $config);

        case 'update':
            return handleUpdate($entityType, $entityData, $lkAPI, $logger, $config);

        case 'delete':
            return handleDelete($entityType, $entityData, $lkAPI, $logger);

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
function handleCreate($entityType, $entityData, $lkAPI, $logger, $config)
{
    switch ($entityType) {
        case 'contact':
            // Проверяем, нужно ли создавать ЛК для этого контакта
            $lkFieldName = $config['field_mapping']['contact']['lk_client_field'];
            $lkField = $entityData[$lkFieldName] ?? null;
            if (!empty($lkField)) {
                $logger->info('Creating LK for new contact', ['contact_id' => $entityData['ID']]);
                return $lkAPI->createLK($entityData);
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
function handleUpdate($entityType, $entityData, $lkAPI, $logger, $config)
{
    switch ($entityType) {
        case 'contact':
            return handleContactUpdate($entityData, $lkAPI, $logger, $config);

        case 'company':
            return handleCompanyUpdate($entityData, $lkAPI, $logger, $config);

        case 'deal':
            return handleDealUpdate($entityData, $lkAPI, $logger, $config);

        case 'smart_process':
            return handleSmartProcessUpdate($entityData, $lkAPI, $logger, $config);
    }

    return true;
}

/**
 * Обработка обновления контакта
 */
function handleContactUpdate($contactData, $lkAPI, $logger, $config)
{
    $contactId = $contactData['ID'];
    $lkFieldName = $config['field_mapping']['contact']['lk_client_field'];
    $lkField = $contactData[$lkFieldName] ?? null;

    // Если поле ЛК установлено и содержит ID ЛК
    if (!empty($lkField)) {
        $lkId = is_array($lkField) ? ($lkField[0] ?? null) : $lkField;

        if ($lkId) {
            // Синхронизация данных контакта в существующий ЛК
            $logger->info('Syncing contact update to existing LK', [
                'contact_id' => $contactId,
                'lk_id' => $lkId
            ]);
            return $lkAPI->syncContact($lkId, $contactData);
        } else {
            // Создание нового ЛК для контакта
            $logger->info('Creating new LK for contact', ['contact_id' => $contactId]);
            return $lkAPI->createLK($contactData);
        }
    }

    // Если поле ЛК было снято - потенциально удаление ЛК
    // (нужно определить логику по требованиям проекта)

    return true;
}

/**
 * Обработка обновления компании
 */
function handleCompanyUpdate($companyData, $lkAPI, $logger, $config)
{
    $companyId = $companyData['ID'];
    $lkFieldName = $config['field_mapping']['company']['lk_client_field'];
    $lkField = $companyData[$lkFieldName] ?? null;

    if (!empty($lkField)) {
        $lkId = is_array($lkField) ? ($lkField[0] ?? null) : $lkField;

        if ($lkId) {
            $logger->info('Syncing company update to LK', [
                'company_id' => $companyId,
                'lk_id' => $lkId
            ]);
            return $lkAPI->syncCompany($lkId, $companyData);
        }
    }

    return true;
}

/**
 * Обработка обновления сделки
 */
function handleDealUpdate($dealData, $lkAPI, $logger, $config)
{
    // Логика обработки изменений сделок
    // Может включать обновление статусов проектов в ЛК
    $logger->info('Deal updated', [
        'deal_id' => $dealData['ID'],
        'stage' => $dealData['STAGE_ID'] ?? 'unknown'
    ]);

    return true;
}

/**
 * Обработка обновления смарт-процесса
 */
function handleSmartProcessUpdate($processData, $lkAPI, $logger, $config)
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
function handleDelete($entityType, $entityData, $lkAPI, $logger)
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
            $headers[$headerName] = $value;
        } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'])) {
            $headerName = str_replace('_', '-', $key);
            $headers[$headerName] = $value;
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

