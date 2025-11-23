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

    // Временная отладка для смарт-процессов
    if (str_contains($eventName, 'DYNAMICITEM')) {
        $logger->info('=== SMART PROCESS EVENT DEBUG ===', [
            'event_name' => $eventName,
            'entity_id' => $entityId,
            'entity_type_determined' => $bitrixAPI->getEntityTypeFromEvent($eventName),
            'smart_process_id_config' => $config['bitrix24']['smart_process_id'] ?? 'NOT_SET',
            'php_version' => PHP_VERSION,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }

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
 * Извлечение ID контакта из значения (может быть строкой или массивом)
 */
function extractContactId($rawValue)
{
    if (is_array($rawValue)) {
        return !empty($rawValue) ? (string)$rawValue[0] : null;
    }
    return !empty($rawValue) ? (string)$rawValue : null;
}

/**
 * Маппинг данных проекта из Bitrix24 в локальный формат
 */
function mapProjectData($projectData, $mapping, $logger)
{
    $projectId = $projectData['id'] ?? $projectData['ID'] ?? null;
    $clientId = extractContactId($projectData[$mapping['client_id']] ?? null);
    
    return [
        'bitrix_id' => $projectId,
        'organization_name' => $projectData[$mapping['organization_name']] ?? '',
        'object_name' => $projectData[$mapping['object_name']] ?? '',
        'system_type' => $projectData[$mapping['system_type']] ?? '',
        'location' => $projectData[$mapping['location']] ?? '',
        'implementation_date' => $projectData[$mapping['implementation_date']] ?? null,
        'status' => $projectData[$mapping['status']] ?? 'NEW',
        'client_id' => $clientId,
        'manager_id' => $projectData['assignedById'] ?? $projectData['ASSIGNED_BY_ID'] ?? null
    ];
}

/**
 * Проверка существования контакта в локальном хранилище
 */
function hasContactInLocalStorage($contactId, $localStorage, $logger)
{
    if (empty($contactId)) {
        return false;
    }
    
    $contact = $localStorage->getContact($contactId);
    $exists = $contact !== null;
    
    $logger->debug('Checking contact existence in local storage', [
        'contact_id' => $contactId,
        'exists' => $exists
    ]);
    
    return $exists;
}

/**
 * Получение и синхронизация менеджера для контакта
 */
function syncManagerForContact($contactId, $assignedById, $bitrixAPI, $localStorage, $logger)
{
    if (empty($assignedById)) {
        return false;
    }
    
    $logger->info('Fetching manager data for contact', [
        'contact_id' => $contactId,
        'assigned_by_id' => $assignedById
    ]);
    
    $managerData = $bitrixAPI->getEntityData('user', $assignedById);
    if ($managerData && isset($managerData['result'])) {
        // Для user.get result может быть массивом, берем первый элемент
        $userData = $managerData['result'];
        if (is_array($userData) && isset($userData[0])) {
            $userData = $userData[0];
        }
        
        $localStorage->syncManagerByBitrixId($assignedById, $userData);
        $logger->info('Manager created/updated for contact', [
            'contact_id' => $contactId,
            'manager_id' => $assignedById
        ]);
        return true;
    } else {
        $logger->warning('Failed to fetch manager data', [
            'contact_id' => $contactId,
            'assigned_by_id' => $assignedById
        ]);
        return false;
    }
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
 * Проверка значения поля ЛК для удаления данных
 */
function shouldDeleteContactData($entityType, $entityData, $config, $logger)
{
    // Проверяем, есть ли настройки поля ЛК клиента для этого типа сущности
    if (!isset($config['field_mapping'][$entityType]['lk_client_field'])) {
        return false;
    }

    $fieldName = $config['field_mapping'][$entityType]['lk_client_field'];
    $deleteValue = $config['field_mapping'][$entityType]['lk_delete_value'] ?? '';

    // Если значение для удаления не задано, пропускаем проверку
    if (empty($deleteValue)) {
        return false;
    }

    $fieldValue = $entityData[$fieldName] ?? null;

    if (empty($fieldValue)) {
        return false;
    }

    // Приводим к строке для сравнения (чтобы корректно сравнивать число и строку)
    $fieldValue = (string)$fieldValue;
    $deleteValue = (string)$deleteValue;

    $shouldDelete = ($fieldValue === $deleteValue);

    $logger->info('Checking if contact data should be deleted', [
        'entity_type' => $entityType,
        'field_name' => $fieldName,
        'field_value' => $fieldValue,
        'delete_value' => $deleteValue,
        'should_delete' => $shouldDelete
    ]);

    return $shouldDelete;
}

/**
 * Основная логика обработки события
 */
function processEvent($eventName, $webhookData, $bitrixAPI, $localStorage, $logger, $config)
{
    $entityType = $bitrixAPI->getEntityTypeFromEvent($eventName);
    $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

    // Отладка для смарт-процессов
    if (str_contains($eventName, 'DYNAMICITEM')) {
        $logger->info('=== PROCESS EVENT DEBUG ===', [
            'event' => $eventName,
            'entity_type_result' => $entityType,
            'entity_id' => $entityId,
            'webhook_data_keys' => array_keys($webhookData),
            'data_keys' => isset($webhookData['data']) ? array_keys($webhookData['data']) : [],
            'fields_keys' => isset($webhookData['data']['FIELDS']) ? array_keys($webhookData['data']['FIELDS']) : []
        ]);
    }

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

    // Обработка структуры ответа API для разных типов сущностей
    if ($entityType === 'smart_process') {
        // Для смарт-процессов данные могут быть в result.item или напрямую в result
        $logger->debug('Processing smart process entity data structure', [
            'original_structure' => array_keys($entityData),
            'has_result_item' => isset($entityData['result']['item']),
            'has_result_array' => isset($entityData['result']) && is_array($entityData['result']),
            'result_keys' => isset($entityData['result']) ? array_keys($entityData['result']) : []
        ]);

        if (isset($entityData['result']['item'])) {
            $entityData = $entityData['result']['item'];
            $logger->debug('Using result.item for smart process data');
        } elseif (isset($entityData['result']) && is_array($entityData['result'])) {
            $entityData = $entityData['result'];
            $logger->debug('Using result array for smart process data');
        } else {
            $logger->error('Unexpected smart process data structure', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'data_keys' => array_keys($entityData)
            ]);
            return false;
        }
    } else {
        $entityData = $entityData['result'];
    }

    // Определение действия на основе события
    $action = getActionFromEvent($eventName);

    switch ($action) {
        case 'create':
            return handleCreate($entityType, $entityData, $localStorage, $bitrixAPI, $logger, $config);

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
function handleCreate($entityType, $entityData, $localStorage, $bitrixAPI, $logger, $config)
{
    switch ($entityType) {
        case 'contact':
            $contactId = $entityData['ID'];
            
            // Проверяем, существует ли ЛК на сайте
            $existingContact = $localStorage->getContact($contactId);
            if ($existingContact) {
                $logger->info('LK already exists for contact, skipping creation', [
                    'contact_id' => $contactId,
                    'lk_id' => $existingContact['id']
                ]);
                return true; // ЛК уже существует, не создаем повторно
            }
            
            // Проверяем, нужно ли создавать ЛК для этого контакта
            // Создание зависит от поля lk_client_field (field_lk) - должно иметь допустимое значение
            if (isValidLKClientValue('contact', $entityData, $config, $logger)) {
                $logger->info('Creating LK for new contact with valid LK client field', [
                    'contact_id' => $contactId,
                    'lk_client_field' => $config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                    'lk_client_value' => $entityData[$config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
                ]);
                $result = $localStorage->createLK($entityData);
                if ($result) {
                    // После создания ЛК, подтягиваем все связанные сущности
                    syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger);
                    
                    // Получаем и синхронизируем менеджера, если указан ASSIGNED_BY_ID
                    $managerField = $config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                    $assignedById = $entityData[$managerField] ?? null;
                    syncManagerForContact($contactId, $assignedById, $bitrixAPI, $localStorage, $logger);
                }
                return $result;
            } else {
                $logger->info('Skipping LK creation for new contact - invalid LK client field value', ['contact_id' => $contactId]);
            }
            break;

        case 'company':
            $contactId = extractContactId($entityData['CONTACT_ID'] ?? null);
            
            if (empty($contactId) || !hasContactInLocalStorage($contactId, $localStorage, $logger)) {
                $logger->info('Skipping company creation - no contact link or contact not found in local storage', [
                    'company_id' => $entityData['ID'],
                    'contact_id' => $contactId
                ]);
                return true;
            }
            
            $logger->info('New company created', [
                'company_id' => $entityData['ID'],
                'contact_id' => $contactId
            ]);
            $localStorage->createCompany($entityData);
            break;

        case 'deal':
            $contactId = extractContactId($entityData['CONTACT_ID'] ?? null);
            
            if (empty($contactId) || !hasContactInLocalStorage($contactId, $localStorage, $logger)) {
                $logger->info('Skipping deal creation - no contact link or contact not found in local storage', [
                    'deal_id' => $entityData['ID'],
                    'contact_id' => $contactId
                ]);
                return true;
            }
            
            $logger->info('New deal created', [
                'deal_id' => $entityData['ID'],
                'contact_id' => $contactId
            ]);
            $localStorage->addDeal($entityData);
            break;

        case 'smart_process':
            $projectId = $entityData['id'] ?? $entityData['ID'] ?? null;
            $logger->info('New smart process created', ['process_id' => $projectId]);
            
            $mapping = $config['field_mapping']['smart_process'];
            $mappedProjectData = mapProjectData($entityData, $mapping, $logger);
            $clientId = $mappedProjectData['client_id'];
            
            if (empty($clientId) || !hasContactInLocalStorage($clientId, $localStorage, $logger)) {
                $logger->info('Skipping project creation - no client link or client not found in local storage', [
                    'project_id' => $projectId,
                    'client_id' => $clientId
                ]);
                return true;
            }
            
            $localStorage->addProject($mappedProjectData);
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
            return handleSmartProcessUpdate($entityData, $localStorage, $bitrixAPI, $logger, $config);
    }

    return true;
}

/**
 * Обработка обновления контакта
 * 
 * ЛК может создаваться через ONCRMCONTACTUPDATE если:
 * 1. Поле lk_client_field имеет допустимое значение (проверяется через isValidLKClientValue)
 * 2. ЛК еще не существует на сайте (проверяется через getContact)
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

    // Проверяем, нужно ли удалить данные контакта при определенном значении поля ЛК
    if (shouldDeleteContactData('contact', $contactData, $config, $logger)) {
        $logger->info('Deleting contact data due to LK field value', [
            'contact_id' => $contactId,
            'lk_field_value' => $contactData[$config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
        ]);
        
        $deleteResult = $localStorage->deleteContactData($contactId);
        
        if ($deleteResult) {
            $logger->info('Contact data deleted successfully', ['contact_id' => $contactId]);
        } else {
            $logger->error('Failed to delete contact data', ['contact_id' => $contactId]);
        }
        
        return $deleteResult;
    }

    // Проверяем, существует ли ЛК на сайте (проверка существования контакта в локальном хранилище)
    $existingContact = $localStorage->getContact($contactId);

    if ($existingContact) {
        // ЛК уже существует - проверяем, допустимо ли значение поля lk_client_field перед обновлением
        // Если поле невалидно, обновление пропускается, но ЛК остается
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Updating existing contact with full data from Bitrix24 - valid LK field', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            $result = $localStorage->syncContactByBitrixId($contactId, $contactData);
            
            return $result;
        } else {
            $logger->info('Skipping contact update - invalid LK client field value', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            return true; // Не считаем это ошибкой, просто пропускаем обновление
        }
    } else {
        // ЛК не существует - проверяем, нужно ли создавать ЛК
        // Создание зависит от поля lk_client_field (field_lk) - должно иметь допустимое значение
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Creating new LK with full data from Bitrix24 - valid LK client field value', [
                'contact_id' => $contactId,
                'lk_client_field' => $config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                'lk_client_value' => $contactData[$config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
            ]);
            $result = $localStorage->createLK($contactData);
            if ($result) {
                // После создания ЛК, подтягиваем все связанные сущности (компании и проекты)
                syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger);
                
                // Получаем и синхронизируем менеджера, если указан ASSIGNED_BY_ID
                $managerField = $config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                $assignedById = $contactData[$managerField] ?? null;
                $logger->debug('Extracting manager ID for contact', [
                    'contact_id' => $contactId,
                    'manager_field' => $managerField,
                    'assigned_by_id' => $assignedById,
                    'has_field' => isset($contactData[$managerField])
                ]);
                syncManagerForContact($contactId, $assignedById, $bitrixAPI, $localStorage, $logger);
            }
            return $result;
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

        // Извлекаем данные из result, так как API возвращает ['result' => [...], 'time' => [...]]
        if (!isset($fullCompanyData['result'])) {
            $logger->error('Company data structure is invalid - missing result key', [
                'company_id' => $companyId,
                'data_keys' => array_keys($fullCompanyData)
            ]);
            return false;
        }

        $companyData = $fullCompanyData['result'];

        // Логируем все ключи для отладки
        $logger->debug('Company data keys from API', [
            'company_id' => $companyId,
            'all_keys' => array_keys($companyData),
            'has_contact_id' => isset($companyData['CONTACT_ID']),
            'contact_id_value' => $companyData['CONTACT_ID'] ?? 'NOT_SET'
        ]);

        $logger->info('Successfully fetched company data from Bitrix24', [
            'company_id' => $companyId,
            'title' => $companyData['TITLE'] ?? 'N/A'
        ]);

        // Логируем CONTACT_ID для отладки
        $rawContactId = $companyData['CONTACT_ID'] ?? null;
        $logger->debug('Raw CONTACT_ID from company data', [
            'company_id' => $companyId,
            'raw_contact_id' => $rawContactId,
            'raw_contact_id_type' => gettype($rawContactId),
            'is_array' => is_array($rawContactId),
            'is_empty' => empty($rawContactId),
            'contact_id_keys' => is_array($rawContactId) ? array_keys($rawContactId) : 'not_array'
        ]);

        // Проверяем, привязана ли компания к контакту с существующим ЛК
        // Используем extractContactId для обработки массива или строки
        $contactId = extractContactId($rawContactId);
        
        $logger->debug('Extracted contact ID', [
            'company_id' => $companyId,
            'raw_contact_id' => $rawContactId,
            'extracted_contact_id' => $contactId,
            'has_contact_in_storage' => !empty($contactId) ? hasContactInLocalStorage($contactId, $localStorage, $logger) : false
        ]);
        
        // Если CONTACT_ID пустой, пытаемся получить связанные контакты через множественную связь
        if (empty($contactId)) {
            $logger->info('CONTACT_ID is empty, trying to get company contacts via crm.company.contact.items.get', [
                'company_id' => $companyId
            ]);
            
            $companyContacts = $bitrixAPI->getCompanyContacts($companyId);
            
            // getCompanyContacts может вернуть false (ошибка) или массив (пустой или с контактами)
            if ($companyContacts !== false && is_array($companyContacts) && !empty($companyContacts)) {
                $logger->info('Found company contacts via API', [
                    'company_id' => $companyId,
                    'contacts_count' => count($companyContacts),
                    'contact_ids' => array_column($companyContacts, 'CONTACT_ID')
                ]);
                
                // Ищем первый контакт, который есть в локальном хранилище
                foreach ($companyContacts as $contactItem) {
                    $linkedContactId = extractContactId($contactItem['CONTACT_ID'] ?? null);
                    if (!empty($linkedContactId) && hasContactInLocalStorage($linkedContactId, $localStorage, $logger)) {
                        $contactId = $linkedContactId;
                        $logger->info('Found valid contact in local storage from company contacts', [
                            'company_id' => $companyId,
                            'contact_id' => $contactId
                        ]);
                        break;
                    }
                }
                
                if (empty($contactId)) {
                    $logger->info('No contacts from company contacts list found in local storage', [
                        'company_id' => $companyId,
                        'contacts_checked' => count($companyContacts)
                    ]);
                }
            } else {
                $logger->debug('No company contacts found via API', [
                    'company_id' => $companyId
                ]);
            }
        }
        
        if (empty($contactId) || !hasContactInLocalStorage($contactId, $localStorage, $logger)) {
            $logger->info('Skipping company sync - no contact link or contact not found in local storage', [
                'company_id' => $companyId,
                'company_title' => $companyData['TITLE'] ?? 'N/A',
                'contact_id' => $contactId,
                'raw_contact_id' => $rawContactId
            ]);
            return true; // Не считаем это ошибкой, просто пропускаем синхронизацию
        }

        $logger->info('Syncing company by Bitrix ID', [
            'company_id' => $companyId,
            'company_title' => $companyData['TITLE'] ?? 'N/A',
            'contact_id' => $contactId
        ]);

        // Устанавливаем CONTACT_ID в данные компании для сохранения связи
        $companyData['CONTACT_ID'] = $contactId;

        // Синхронизируем компанию через LocalStorage
        $result = $localStorage->syncCompanyByBitrixId($companyId, $companyData);
        if (!$result) {
            $logger->error('Failed to sync company', [
                'company_id' => $companyId
            ]);
            return false;
        }

        return true;

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
    $dealId = $dealData['ID'];
    $contactId = $dealData['CONTACT_ID'] ?? null;
    
    // Проверяем, привязана ли сделка к контакту с существующим ЛК
    if (empty($contactId) || !hasContactInLocalStorage($contactId, $localStorage, $logger)) {
        $logger->info('Skipping deal sync - no contact link or contact not found in local storage', [
            'deal_id' => $dealId,
            'contact_id' => $contactId,
            'stage' => $dealData['STAGE_ID'] ?? 'unknown'
        ]);
        return true; // Не считаем это ошибкой, просто пропускаем синхронизацию
    }

    $logger->info('Deal updated', [
        'deal_id' => $dealId,
        'stage' => $dealData['STAGE_ID'] ?? 'unknown',
        'contact_id' => $contactId
    ]);

    // Синхронизируем данные сделки локально
    return $localStorage->syncDealByBitrixId($dealId, $dealData);
}

/**
 * Обработка обновления смарт-процесса
 */
function handleSmartProcessUpdate($processData, $localStorage, $bitrixAPI, $logger, $config)
{
    $mapping = $config['field_mapping']['smart_process'];
    $mappedProjectData = mapProjectData($processData, $mapping, $logger);
    
    $projectId = $mappedProjectData['bitrix_id'];
    $clientId = $mappedProjectData['client_id'];
    
    $logger->info('Smart process updated', [
        'process_id' => $projectId ?? 'NULL_ID',
        'entity_type' => $processData['ENTITY_TYPE'] ?? 'unknown'
    ]);

    if (empty($clientId) || !hasContactInLocalStorage($clientId, $localStorage, $logger)) {
        $logger->info('Skipping project sync - no client link or client not found in local storage', [
            'project_id' => $projectId,
            'client_id' => $clientId,
            'organization_name' => $mappedProjectData['organization_name']
        ]);
        return true;
    }

    if (empty($projectId)) {
        $logger->error('Cannot sync project - no valid ID found', ['process_data' => $processData]);
        return false;
    }

    $logger->debug('Syncing project to local storage', [
        'project_id' => $projectId,
        'client_id' => $clientId,
        'organization_name' => $mappedProjectData['organization_name']
    ]);
    
    return $localStorage->syncProjectByBitrixId($projectId, $mappedProjectData);
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
 * Синхронизация связанных компаний для контакта
 */
function syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger)
{
    $logger->info('Checking for related companies for contact', ['contact_id' => $contactId]);

    try {
        // Ищем компании, где текущий контакт указан как CONTACT_ID
        $companies = $bitrixAPI->getEntityList('company', [
            'filter' => ['CONTACT_ID' => $contactId]
        ]);

        if ($companies && isset($companies['result'])) {
            $companyList = $companies['result'];
            $logger->info('Found related companies for contact', [
                'contact_id' => $contactId,
                'companies_count' => count($companyList)
            ]);

            foreach ($companyList as $company) {
                $companyId = $company['ID'];
                $logger->info('Processing related company', [
                    'company_id' => $companyId,
                    'company_title' => $company['TITLE'] ?? 'N/A',
                    'contact_id' => $contactId
                ]);

                // Получаем полные данные компании
                $fullCompanyData = $bitrixAPI->getEntityData('company', $companyId);
                if ($fullCompanyData && isset($fullCompanyData['result'])) {
                    $companyData = $fullCompanyData['result'];

                    // Синхронизируем компанию с ЛК контакта
                    // Убираем строгую проверку CONTACT_ID, так как в Битрикс24 это поле может быть не всегда корректно установлено
                        $logger->info('Syncing related company to contact LK', [
                            'company_id' => $companyId,
                            'company_title' => $companyData['TITLE'] ?? 'N/A',
                        'contact_id' => $contactId,
                        'company_contact_id' => $companyData['CONTACT_ID'] ?? null
                        ]);

                    // Устанавливаем правильный CONTACT_ID для связи с контактом
                    $companyData['CONTACT_ID'] = $contactId;

                    $syncResult = $localStorage->syncCompanyByBitrixId($companyId, $companyData);
                        if (!$syncResult) {
                            $logger->error('Failed to sync related company', [
                                'company_id' => $companyId,
                                'contact_id' => $contactId
                        ]);
                    }
                } else {
                    $logger->error('Failed to get company data from Bitrix24 API', [
                        'company_id' => $companyId,
                        'contact_id' => $contactId
                    ]);
                }
            }
        } else {
            $logger->debug('No related companies found for contact', ['contact_id' => $contactId]);
        }

        // Ищем связанные проекты (смарт-процессы)
        $logger->info('Checking for related projects for contact', ['contact_id' => $contactId]);

        try {
            $smartProcessId = $config['bitrix24']['smart_process_id'] ?? null;
            if ($smartProcessId) {
                // Ищем смарт-процессы, где текущий контакт указан как клиент
                // Используем поле contactId из маппинга для фильтрации
                $mapping = $config['field_mapping']['smart_process'];
                $clientFieldName = $mapping['client_id'] ?? 'contactId';
                
                $logger->debug('Searching projects by contact', [
                    'contact_id' => $contactId,
                    'client_field_name' => $clientFieldName,
                    'smart_process_id' => $smartProcessId
                ]);
                
                $projects = $bitrixAPI->getEntityList('smart_process', [
                    'filter' => [$clientFieldName => $contactId]
                ]);

                $logger->debug('Projects API response', [
                    'contact_id' => $contactId,
                    'has_result' => isset($projects['result']),
                    'result_type' => gettype($projects),
                    'result_keys' => is_array($projects) ? array_keys($projects) : 'not_array'
                ]);

                if ($projects && isset($projects['result'])) {
                    $projectList = $projects['result'];
                    $logger->info('Found related projects for contact', [
                        'contact_id' => $contactId,
                        'projects_count' => is_array($projectList) ? count($projectList) : 0,
                        'project_list_type' => gettype($projectList)
                    ]);

                    foreach ($projectList as $project) {
                        $projectId = $project['ID'] ?? null;

                        // Пропускаем проекты без ID
                        if (empty($projectId)) {
                            $logger->warning('Skipping project without ID in list', [
                                'contact_id' => $contactId,
                                'project_data' => $project
                            ]);
                            continue;
                        }

                        $logger->info('Processing related project', [
                            'project_id' => $projectId,
                            'project_title' => $project['TITLE'] ?? 'N/A',
                            'contact_id' => $contactId
                        ]);

                        // Получаем полные данные проекта
                        $fullProjectData = $bitrixAPI->getEntityData('smart_process', $projectId);
                        if (!$fullProjectData) {
                            $logger->error('Failed to get project data from Bitrix24 API', [
                                'project_id' => $projectId,
                                'contact_id' => $contactId
                            ]);
                            continue;
                        }

                        // Обрабатываем структуру ответа API для смарт-процессов
                        $projectData = null;
                        if (isset($fullProjectData['result']['item'])) {
                            $projectData = $fullProjectData['result']['item'];
                        } elseif (isset($fullProjectData['result']) && is_array($fullProjectData['result'])) {
                            $projectData = $fullProjectData['result'];
                        }

                        if (!$projectData) {
                            $logger->error('Unexpected project data structure from Bitrix24 API', [
                                'project_id' => $projectId,
                                'contact_id' => $contactId,
                                'data_keys' => array_keys($fullProjectData)
                            ]);
                            continue;
                        }

                        // Проверяем, что contactId все еще указывает на наш контакт
                        $mapping = $config['field_mapping']['smart_process'];
                        $projectContactId = extractContactId($projectData[$mapping['client_id']] ?? null);

                        if ($projectContactId === $contactId) {
                            // Маппируем данные проекта используя функцию mapProjectData
                            $mappedProjectData = mapProjectData($projectData, $mapping, $logger);
                            
                            $logger->info('Syncing related project to contact LK', [
                                'project_id' => $projectId,
                                'project_title' => $projectData['title'] ?? $projectData['TITLE'] ?? 'N/A',
                                'contact_id' => $contactId,
                                'project_contact_id' => $projectContactId
                            ]);

                            // Синхронизировать проект через LocalStorage с маппированными данными
                            $syncResult = $localStorage->syncProjectByBitrixId($projectId, $mappedProjectData);
                            if (!$syncResult) {
                                $logger->error('Failed to sync related project', [
                                    'project_id' => $projectId,
                                    'contact_id' => $contactId
                                ]);
                            } else {
                                $logger->info('Successfully synced related project', [
                                    'project_id' => $projectId,
                                    'contact_id' => $contactId
                                ]);
                            }
                        } else {
                            $logger->warning('Project client does not match contact, skipping', [
                                'project_id' => $projectId,
                                'expected_contact_id' => $contactId,
                                'actual_contact_id' => $projectContactId
                            ]);
                        }
                    }
                } else {
                    $logger->debug('No related projects found for contact', ['contact_id' => $contactId]);
                }
            } else {
                $logger->warning('Smart process ID not configured, skipping project sync', ['contact_id' => $contactId]);
            }
        } catch (Exception $e) {
            $logger->error('Error syncing related projects for contact', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);
        }

        // Ищем связанные сделки
        $logger->info('Checking for related deals for contact', ['contact_id' => $contactId]);

        try {
            $deals = $bitrixAPI->getEntityList('deal', [
                'filter' => ['CONTACT_ID' => $contactId]
            ]);

            if ($deals && isset($deals['result'])) {
                $dealList = $deals['result'];
                $logger->info('Found related deals for contact', [
                    'contact_id' => $contactId,
                    'deals_count' => count($dealList)
                ]);

                foreach ($dealList as $deal) {
                    $dealId = $deal['ID'];
                    $logger->info('Processing related deal', [
                        'deal_id' => $dealId,
                        'deal_title' => $deal['TITLE'] ?? 'N/A',
                        'contact_id' => $contactId
                    ]);

                    // Получаем полные данные сделки
                    $fullDealData = $bitrixAPI->getEntityData('deal', $dealId);
                    if ($fullDealData && isset($fullDealData['result'])) {
                        $dealData = $fullDealData['result'];

                        $logger->info('Syncing related deal to contact LK', [
                            'deal_id' => $dealId,
                            'deal_title' => $dealData['TITLE'] ?? 'N/A',
                            'contact_id' => $contactId
                        ]);

                        $syncResult = $localStorage->syncDealByBitrixId($dealId, $dealData);
                        if (!$syncResult) {
                            $logger->error('Failed to sync related deal', [
                                'deal_id' => $dealId,
                                'contact_id' => $contactId
                            ]);
                        }
                    } else {
                        $logger->error('Failed to get deal data from Bitrix24 API', [
                            'deal_id' => $dealId,
                            'contact_id' => $contactId
                        ]);
                    }
                }
            } else {
                $logger->debug('No related deals found for contact', ['contact_id' => $contactId]);
            }
        } catch (Exception $e) {
            $logger->error('Error syncing related deals for contact', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);
        }

    } catch (Exception $e) {
        $logger->error('Error syncing related entities for contact', [
            'contact_id' => $contactId,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Создание карточки в смарт-процессе "Изменение данных в ЛК"
 * 
 * @param array $data Данные для создания карточки:
 *   - contact_id (обязательно) - ID контакта
 *   - company_id (опционально) - ID компании
 *   - manager_id (опционально) - ID менеджера
 *   - new_email (опционально) - Новый e-mail
 *   - new_phone (опционально) - Новый телефон
 *   - change_reason_personal (опционально) - Причина изменения личных данных
 *   - new_company_name (опционально) - Название новой компании
 *   - new_company_website (опционально) - Сайт новой компании
 *   - new_company_inn (опционально) - ИНН новой компании
 *   - new_company_phone (опционально) - Телефон новой компании
 *   - change_reason_company (опционально) - Причина изменения данных о компании
 * @param Bitrix24API $bitrixAPI Экземпляр API
 * @param Logger $logger Экземпляр логгера
 * @return array|false Результат создания или false при ошибке
 */
function createChangeDataCard($data, $bitrixAPI, $logger)
{
    if (empty($data['contact_id'])) {
        $logger->error('Contact ID is required for creating change data card');
        return false;
    }

    $logger->info('Creating change data card in smart process', [
        'contact_id' => $data['contact_id'],
        'has_company_id' => !empty($data['company_id']),
        'has_manager_id' => !empty($data['manager_id']),
        'has_personal_changes' => !empty($data['new_email']) || !empty($data['new_phone']),
        'has_company_changes' => !empty($data['new_company_name']) || !empty($data['new_company_inn'])
    ]);

    $result = $bitrixAPI->createChangeDataCard($data);

    if ($result) {
        $logger->info('Change data card created successfully', [
            'contact_id' => $data['contact_id'],
            'card_id' => $result['id'] ?? 'unknown'
        ]);
    } else {
        $logger->error('Failed to create change data card', [
            'contact_id' => $data['contact_id']
        ]);
    }

    return $result;
}

/**
 * Создание карточки в смарт-процессе "Удаление пользовательских данных"
 * 
 * @param array $data Данные для создания карточки:
 *   - contact_id (обязательно) - ID контакта
 *   - company_id (опционально) - ID компании
 *   - manager_id (опционально) - ID менеджера
 * @param Bitrix24API $bitrixAPI Экземпляр API
 * @param Logger $logger Экземпляр логгера
 * @return array|false Результат создания или false при ошибке
 */
function createDeleteDataCard($data, $bitrixAPI, $logger)
{
    if (empty($data['contact_id'])) {
        $logger->error('Contact ID is required for creating delete data card');
        return false;
    }

    $logger->info('Creating delete data card in smart process', [
        'contact_id' => $data['contact_id'],
        'has_company_id' => !empty($data['company_id']),
        'has_manager_id' => !empty($data['manager_id'])
    ]);

    $result = $bitrixAPI->createDeleteDataCard($data);

    if ($result) {
        $logger->info('Delete data card created successfully', [
            'contact_id' => $data['contact_id'],
            'card_id' => $result['id'] ?? 'unknown'
        ]);
    } else {
        $logger->error('Failed to create delete data card', [
            'contact_id' => $data['contact_id']
        ]);
    }

    return $result;
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

