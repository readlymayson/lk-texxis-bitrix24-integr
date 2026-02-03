<?php

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
 * - ONCRMDYNAMICITEMUPDATE - изменение смарт-процесса
 * - ONCRMDYNAMICITEMADD - создание смарт-процесса
 * - ONCRMDYNAMICITEMDELETE - удаление смарт-процесса
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

// Проверка доступности необходимых файлов
$configFile = __DIR__ . '/../config/bitrix24.php';
if (!file_exists($configFile)) {
    error_log('ERROR: Config file not found: ' . $configFile);
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Configuration file not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require_once $configFile;

if (empty($config)) {
    error_log('ERROR: Failed to load configuration');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Failed to load configuration'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Проверка доступности директории логов
$logDir = dirname($config['logging']['file'] ?? __DIR__ . '/../logs/bitrix24_webhooks.log');
if (!is_dir($logDir) || !is_writable($logDir)) {
    error_log('WARNING: Log directory is not writable: ' . $logDir);
    // Не блокируем выполнение, но логируем предупреждение
}

try {
    $logger = new Logger($config);
    $bitrixAPI = new Bitrix24API($config, $logger);
    $localStorage = new LocalStorage($logger, $config);
} catch (Exception $e) {
    error_log('ERROR: Failed to initialize classes: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Internal server error', 'message' => 'Failed to initialize application'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Ограничение размера тела запроса (10MB)
    $maxBodySize = 10 * 1024 * 1024; // 10MB
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    
    if ($contentLength > $maxBodySize) {
        $logger->warning('Request body too large', [
            'content_length' => $contentLength,
            'max_size' => $maxBodySize
        ]);
        sendResponse(413, ['error' => 'Request entity too large']);
        exit;
    }
    
    $rawBody = file_get_contents('php://input');
    
    // Дополнительная проверка размера после чтения
    if (strlen($rawBody) > $maxBodySize) {
        $logger->warning('Request body too large after reading', [
            'body_size' => strlen($rawBody),
            'max_size' => $maxBodySize
        ]);
        sendResponse(413, ['error' => 'Request entity too large']);
        exit;
    }
    
    $headers = getRequestHeaders();

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

    $logger->debug('Webhook headers', ['headers' => $headers]);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $logger->warning('Invalid request method', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'expected' => 'POST'
        ]);
        sendResponse(405, ['error' => 'Method not allowed. Use POST.']);
        exit;
    }

    if (empty($rawBody)) {
        $logger->warning('Empty request body received', [
            'content_length' => $_SERVER['CONTENT_LENGTH'] ?? 'not set',
            'headers' => $headers
        ]);
        sendResponse(400, ['error' => 'Empty request body']);
        exit;
    }

    $webhookData = $bitrixAPI->validateWebhookRequest($headers, $rawBody);
    if ($webhookData === false) {
        $logger->error('=== WEBHOOK VALIDATION FAILED ===', [
            'raw_body_preview' => strlen($rawBody) > 500 ? substr($rawBody, 0, 500) . '...' : $rawBody,
            'raw_body_size' => strlen($rawBody),
            'headers' => $headers,
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'UNKNOWN',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
            'note' => 'Check logs above for specific validation errors'
        ]);
        sendResponse(400, ['error' => 'Invalid webhook request', 'message' => 'Validation failed. Check server logs for details.']);
        exit;
    }

    $logger->debug('Webhook data validated', [
        'event' => $webhookData['event'] ?? 'UNKNOWN',
        'event_type' => getWebhookEventType($webhookData['event'] ?? ''),
        'entity_id' => $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? 'UNKNOWN'
    ]);

    $logger->debug('Full webhook data from Bitrix24', [
        'webhook_data' => $webhookData
    ]);

    $eventName = $webhookData['event'] ?? '';
    $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

    if (str_contains($eventName, 'DYNAMICITEM')) {
        $logger->debug('Smart process event detected', [
            'event_name' => $eventName,
            'entity_id' => $entityId,
            'entity_type_determined' => $bitrixAPI->getEntityTypeFromEvent($eventName)
        ]);
    }

    if (empty($eventName)) {
        $logger->error('=== MISSING EVENT NAME ===', [
            'webhook_data_keys' => array_keys($webhookData),
            'webhook_data_structure' => [
                'has_event' => isset($webhookData['event']),
                'event_value' => $webhookData['event'] ?? null,
                'has_data' => isset($webhookData['data']),
                'data_keys' => isset($webhookData['data']) ? array_keys($webhookData['data']) : []
            ],
            'body_preview' => strlen($rawBody) > 500 ? substr($rawBody, 0, 500) . '...' : $rawBody
        ]);
        sendResponse(400, ['error' => 'Missing event name', 'message' => 'Webhook data does not contain event field']);
        exit;
    }

    $logger->info('=== STARTING EVENT PROCESSING ===', [
        'event_name' => $eventName,
        'event_type' => getWebhookEventType($eventName),
        'action' => getActionFromEvent($eventName),
        'entity_id' => $entityId,
        'processing_start_time' => date('Y-m-d H:i:s')
    ]);

    $result = processEventWithRetry($eventName, $webhookData, $config, $logger, $bitrixAPI, $localStorage);

    $processingEndTime = date('Y-m-d H:i:s');
    $processingDuration = time() - strtotime($webhookData['ts'] ?? 'now');

    if ($result) {
        $logger->debug('Event processing completed successfully', [
            'event_name' => $eventName,
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

            // Если это не последняя попытка, логируем задержку
            // Повторные попытки должны обрабатываться внешней системой (очередь, cron)
            // sleep() не используется, чтобы не блокировать webhook
            if ($attempt < $maxRetries) {
                $delay = $retryDelays[$attempt] ?? end($retryDelays);
                $logger->warning('Event processing failed, retry recommended', [
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxRetries + 1,
                    'recommended_delay' => $delay,
                    'event' => $eventName,
                    'note' => 'Retries should be handled by external queue system'
                ]);
            }

        } catch (Exception $e) {
            $logger->error('Exception during event processing', [
                'event' => $eventName,
                'attempt' => $attempt + 1,
                'error' => $e->getMessage()
            ]);

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
            $systemTypes = [(string)$systemTypesRaw];
        }
    }
    
    // Обработка поля "Перечень оборудования" (тип: Ссылка) - множественное поле
    $equipmentListRaw = $projectData[$mapping['equipment_list']] ?? null;
    $equipmentList = [];
    if (!empty($equipmentListRaw)) {
        // Поле типа "Ссылка" с множественным выбором - может быть массивом файлов
        if (is_array($equipmentListRaw)) {
            // Обрабатываем каждый файл в массиве
            foreach ($equipmentListRaw as $fileData) {
                if (is_array($fileData)) {
                    // Если это объект с данными файла
                    $fileInfo = [
                        'id' => $fileData['id'] ?? $fileData['ID'] ?? null,
                        'name' => $fileData['name'] ?? $fileData['NAME'] ?? null,
                        'url' => $fileData['downloadUrl'] ?? $fileData['DOWNLOAD_URL'] ?? null,
                        'size' => $fileData['size'] ?? $fileData['SIZE'] ?? null
                    ];
                    if (!empty($fileInfo['id'])) {
                        $equipmentList[] = $fileInfo;
                    }
                } else {
                    // Если это просто ID файла
                    if (!empty($fileData)) {
                        $equipmentList[] = ['id' => $fileData];
                    }
                }
            }
        } else {
            // Если это одиночное значение (ID файла как ссылка) - преобразуем в массив
            $equipmentList[] = ['id' => $equipmentListRaw];
        }
    }
    
    // Обработка чекбокса "Маркетинговая скидка"
    $marketingDiscountRaw = $projectData[$mapping['marketing_discount']] ?? null;
    $marketingDiscount = false;
    if (!empty($marketingDiscountRaw)) {
        // Bitrix24 может передавать: true, 'Y', 1, '1'
        if (is_bool($marketingDiscountRaw)) {
            $marketingDiscount = $marketingDiscountRaw;
        } elseif (is_numeric($marketingDiscountRaw)) {
            $marketingDiscount = (int)$marketingDiscountRaw === 1;
        } elseif (is_string($marketingDiscountRaw)) {
            $marketingDiscount = in_array(strtoupper($marketingDiscountRaw), ['Y', 'YES', 'TRUE', '1']);
        }
    }
    
    // Техническое описание проекта (многострочный текст)
    $technicalDescription = $projectData[$mapping['technical_description']] ?? '';
    
    // Обработка поля "Местоположение" (тип: address)
    // Bitrix24 возвращает адрес в формате "адрес|;|ID_смартпроцесса"
    // Нужно извлечь только адресную часть
    $locationRaw = $projectData[$mapping['location']] ?? '';
    $location = '';
    if (!empty($locationRaw)) {
        // Если адрес содержит разделитель "|;|", берем только часть до разделителя
        if (str_contains($locationRaw, '|;|')) {
            $locationParts = explode('|;|', $locationRaw);
            $location = trim($locationParts[0]);
        } else {
            $location = trim($locationRaw);
        }
    }
    
    return [
        'bitrix_id' => $projectId,
        'organization_name' => $projectData[$mapping['organization_name']] ?? '',
        'object_name' => $projectData[$mapping['object_name']] ?? '',
        'system_types' => $systemTypes,
        'location' => $location,
        'implementation_date' => $projectData[$mapping['implementation_date']] ?? null,
        'request_type' => $requestType,
        'equipment_list' => $equipmentList,
        'equipment_list_text' => $projectData[$mapping['equipment_list_text']] ?? '',
        'competitors' => $projectData[$mapping['competitors']] ?? '',
        'marketing_discount' => $marketingDiscount,
        'technical_description' => $technicalDescription,
        'status' => $projectData[$mapping['status']] ?? 'NEW',
        'client_id' => $clientId,
        'company_id' => $companyId,
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
    
    $logger->debug('Fetching manager data for contact', [
        'contact_id' => $contactId,
        'assigned_by_id' => $assignedById
    ]);
    
    $managerData = $bitrixAPI->getEntityData('user', $assignedById);
    if ($managerData && isset($managerData['result'])) {
        $userData = $managerData['result'];
        if (is_array($userData) && isset($userData[0])) {
            $userData = $userData[0];
        }
        
        $localStorage->syncManagerByBitrixId($assignedById, $userData);
        $logger->debug('Manager created/updated for contact', [
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
    if (!isset($config['field_mapping'][$entityType]['lk_client_field'])) {
        $logger->debug('LK client field not configured for entity type', [
            'entity_type' => $entityType
        ]);
        return false;
    }

    $fieldName = $config['field_mapping'][$entityType]['lk_client_field'];
    $allowedValues = $config['field_mapping'][$entityType]['lk_client_values'] ?? [];

    $fieldValue = $entityData[$fieldName] ?? null;

    if (empty($fieldValue) && $fieldValue !== '0' && $fieldValue !== 0) {
        $logger->debug('LK client field is empty or not set', [
            'entity_type' => $entityType,
            'field_name' => $fieldName,
            'field_value' => $fieldValue
        ]);
        return false;
    }

    // Нормализуем значения для сравнения (приводим к строкам)
    // Bitrix24 может возвращать значения как строки или числа
    $fieldValueNormalized = (string)$fieldValue;
    $allowedValuesNormalized = array_map('strval', $allowedValues);

    $isValid = in_array($fieldValueNormalized, $allowedValuesNormalized, true);

    $logger->debug('LK client field validation', [
        'entity_type' => $entityType,
        'field_name' => $fieldName,
        'field_value' => $fieldValue,
        'field_value_type' => gettype($fieldValue),
        'field_value_normalized' => $fieldValueNormalized,
        'allowed_values' => $allowedValues,
        'allowed_values_normalized' => $allowedValuesNormalized,
        'is_valid' => $isValid
    ]);

    return $isValid;
}

/**
 * Проверка значения поля ЛК для удаления данных
 */
function shouldDeleteContactData($entityType, $entityData, $config, $logger)
{
    if (!isset($config['field_mapping'][$entityType]['lk_client_field'])) {
        return false;
    }

    $fieldName = $config['field_mapping'][$entityType]['lk_client_field'];
    $deleteValue = $config['field_mapping'][$entityType]['lk_delete_value'] ?? '';

    if (empty($deleteValue) && $deleteValue !== '0' && $deleteValue !== 0) {
        return false;
    }

    $fieldValue = $entityData[$fieldName] ?? null;

    if (empty($fieldValue) && $fieldValue !== '0' && $fieldValue !== 0) {
        return false;
    }

    // Нормализуем значения для сравнения (приводим к строкам)
    // Bitrix24 может возвращать значения как строки или числа
    $fieldValueNormalized = (string)$fieldValue;
    $deleteValueNormalized = (string)$deleteValue;

    $shouldDelete = ($fieldValueNormalized === $deleteValueNormalized);

    $logger->debug('Checking if contact data should be deleted', [
        'entity_type' => $entityType,
        'field_name' => $fieldName,
        'field_value' => $fieldValue,
        'field_value_type' => gettype($fieldValue),
        'field_value_normalized' => $fieldValueNormalized,
        'delete_value' => $deleteValue,
        'delete_value_type' => gettype($deleteValue),
        'delete_value_normalized' => $deleteValueNormalized,
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

    $logger->debug('Processing event', [
        'event' => $eventName,
        'entity_type' => $entityType,
        'entity_id' => $entityId
    ]);

    $entityData = $bitrixAPI->getEntityData($entityType, $entityId);
    
    // Проверяем, является ли ошибка "Not found"
    if (is_array($entityData) && isset($entityData['error']) && $entityData['error'] === 'NOT_FOUND') {
        $logger->info('Entity not found in Bitrix24, deleting from local storage', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
        
        // Удаляем элемент из локального хранилища
        $deleted = false;
        switch ($entityType) {
            case 'contact':
                $deleted = $localStorage->deleteContactData($entityId);
                break;
            case 'company':
                $deleted = $localStorage->deleteCompany($entityId);
                break;
            case 'smart_process':
                $deleted = $localStorage->deleteProject($entityId);
                break;
            default:
                $logger->warning('Unknown entity type for deletion', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]);
        }
        
        if ($deleted) {
            $logger->info('Entity successfully deleted from local storage', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return true; // Считаем успешным, так как элемент удален
        } else {
            $logger->warning('Failed to delete entity from local storage (may not exist)', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return true; // Считаем успешным, элемент мог не существовать
        }
    }
    
    if (!$entityData || !isset($entityData['result'])) {
        $logger->error('Failed to get entity data from Bitrix24', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'response_type' => gettype($entityData),
            'has_error_key' => is_array($entityData) && isset($entityData['error'])
        ]);
        return false;
    }

    if ($entityType === 'smart_process') {
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
            
            $existingContact = $localStorage->getContact($contactId);
            if ($existingContact) {
                $logger->info('LK already exists for contact, skipping creation', [
                    'contact_id' => $contactId,
                    'lk_id' => $existingContact['id']
                ]);
                return true; // ЛК уже существует, не создаем повторно
            }
            
            if (isValidLKClientValue('contact', $entityData, $config, $logger)) {
                $logger->info('Creating LK for new contact with valid LK client field', [
                    'contact_id' => $contactId,
                    'lk_client_field' => $config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                    'lk_client_value' => $entityData[$config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
                ]);
                $result = $localStorage->createLK($entityData);
                if ($result) {
                    syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger);
                    
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

        case 'smart_process':
            $projectId = $entityData['id'] ?? $entityData['ID'] ?? null;
            $logger->info('New smart process created', ['process_id' => $projectId]);
            
            $mapping = $config['field_mapping']['smart_process'];
            $mappedProjectData = mapProjectData($entityData, $mapping, $logger, $localStorage);
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

    $existingContact = $localStorage->getContact($contactId);

    if ($existingContact) {
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Updating existing contact with full data from Bitrix24 - valid LK field', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            $result = $localStorage->syncContactByBitrixId($contactId, $contactData);
            if ($result) {
                // Синхронизируем менеджера при обновлении существующего контакта
                $managerField = $config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                $assignedById = $contactData[$managerField] ?? null;
                $logger->debug('Extracting manager ID for existing contact update', [
                    'contact_id' => $contactId,
                    'manager_field' => $managerField,
                    'assigned_by_id' => $assignedById,
                    'has_field' => isset($contactData[$managerField])
                ]);
                syncManagerForContact($contactId, $assignedById, $bitrixAPI, $localStorage, $logger);
            }

            return $result;
        } else {
            $logger->info('Skipping contact update - invalid LK client field value', [
                'contact_id' => $contactId,
                'lk_id' => $existingContact['id']
            ]);
            return true;
        }
    } else {
        if (isValidLKClientValue('contact', $contactData, $config, $logger)) {
            $logger->info('Creating new LK with full data from Bitrix24 - valid LK client field value', [
                'contact_id' => $contactId,
                'lk_client_field' => $config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                'lk_client_value' => $contactData[$config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
            ]);
            $result = $localStorage->createLK($contactData);
            if ($result) {
                syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger);
                
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

        $rawContactId = $companyData['CONTACT_ID'] ?? null;
        $logger->debug('Raw CONTACT_ID from company data', [
            'company_id' => $companyId,
            'raw_contact_id' => $rawContactId,
            'raw_contact_id_type' => gettype($rawContactId),
            'is_array' => is_array($rawContactId),
            'is_empty' => empty($rawContactId),
            'contact_id_keys' => is_array($rawContactId) ? array_keys($rawContactId) : 'not_array'
        ]);

        $contactId = extractContactId($rawContactId);
        
        $logger->debug('Extracted contact ID', [
            'company_id' => $companyId,
            'raw_contact_id' => $rawContactId,
            'extracted_contact_id' => $contactId,
            'has_contact_in_storage' => !empty($contactId) ? hasContactInLocalStorage($contactId, $localStorage, $logger) : false
        ]);
        
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
 * Обработка обновления смарт-процесса
 */
function handleSmartProcessUpdate($processData, $localStorage, $bitrixAPI, $logger, $config)
{
    $mapping = $config['field_mapping']['smart_process'];
    $mappedProjectData = mapProjectData($processData, $mapping, $logger, $localStorage);
    
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

        case 'smart_process':
            $projectId = $entityData['id'] ?? $entityData['ID'] ?? null;
            $logger->info('Smart process deleted', ['project_id' => $projectId ?? 'unknown']);
            if ($projectId) {
                $localStorage->deleteProject($projectId);
            }
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
        $companies = $bitrixAPI->getEntityList('company', ['CONTACT_ID' => $contactId]);

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
                    $companyContactId = extractContactId($companyData['CONTACT_ID'] ?? null);

                    // Проверяем, что компания действительно связана с контактом
                    $isRelated = false;
                    if (!empty($companyContactId) && $companyContactId === (string)$contactId) {
                        $isRelated = true;
                    } elseif (empty($companyContactId)) {
                        // Если CONTACT_ID пустой, проверяем через множественную связь
                        $companyContacts = $bitrixAPI->getCompanyContacts($companyId);
                        if ($companyContacts !== false) {
                            foreach ($companyContacts as $relatedContact) {
                                $relatedContactId = $relatedContact['CONTACT_ID'] ?? $relatedContact['ID'] ?? null;
                                if (!empty($relatedContactId) && (string)$relatedContactId === (string)$contactId) {
                                    $isRelated = true;
                                    break;
                                }
                            }
                        }
                    }

                    if (!$isRelated) {
                        $logger->warning('Skipping company sync - not related to contact', [
                            'company_id' => $companyId,
                            'company_title' => $companyData['TITLE'] ?? 'N/A',
                            'contact_id' => $contactId,
                            'company_contact_id' => $companyContactId
                        ]);
                        continue;
                    }

                    $logger->info('Syncing related company to contact LK', [
                        'company_id' => $companyId,
                        'company_title' => $companyData['TITLE'] ?? 'N/A',
                        'contact_id' => $contactId,
                        'company_contact_id' => $companyContactId
                    ]);

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
                
                $projects = $bitrixAPI->getEntityList('smart_process', [$clientFieldName => $contactId]);

                $logger->debug('Projects API response', [
                    'contact_id' => $contactId,
                    'has_result' => isset($projects['result']),
                    'result_type' => gettype($projects),
                    'result_keys' => is_array($projects) ? array_keys($projects) : 'not_array'
                ]);

                if ($projects && isset($projects['result'])) {
                    // crm.item.list для смарт-процессов возвращает массив с ключом items
                    $projectList = isset($projects['result']['items'])
                        ? $projects['result']['items']
                        : $projects['result'];
                    $logger->info('Found related projects for contact', [
                        'contact_id' => $contactId,
                        'projects_count' => is_array($projectList) ? count($projectList) : 0,
                        'project_list_type' => gettype($projectList)
                    ]);

                    foreach ($projectList as $project) {
                        $projectId = $project['ID'] ?? $project['id'] ?? null;

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
                            $mappedProjectData = mapProjectData($projectData, $mapping, $logger, $localStorage);
                            
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
 * @param string|int $contactId ID контакта (обязательно)
 * @param array $additionalData Дополнительные данные (new_email, new_phone, и т.д.)
 * @param Bitrix24API $bitrixAPI Экземпляр API
 * @param LocalStorage $localStorage Экземпляр LocalStorage для получения данных из ЛК базы
 * @param Logger $logger Экземпляр логгера
 * @return array|false Результат создания или false при ошибке
 */
function createChangeDataCard($contactId, $additionalData, $bitrixAPI, $localStorage, $logger)
{
    if (empty($contactId)) {
        $logger->error('Contact ID is required for creating change data card');
        return false;
    }

    $logger->info('Creating change data card in smart process', [
        'contact_id' => $contactId,
        'has_personal_changes' => !empty($additionalData['new_email']) || !empty($additionalData['new_phone']),
        'has_company_changes' => !empty($additionalData['new_company_name']) || !empty($additionalData['new_company_inn']),
        'note' => 'company_id and manager_id will be retrieved from LK database'
    ]);

    $result = $bitrixAPI->createChangeDataCard($contactId, $additionalData, $localStorage);

    if ($result) {
        $logger->info('Change data card created successfully', [
            'contact_id' => $contactId,
            'card_id' => $result['id'] ?? 'unknown'
        ]);
    } else {
        $logger->error('Failed to create change data card', [
            'contact_id' => $contactId
        ]);
    }

    return $result;
}

/**
 * Создание карточки в смарт-процессе "Удаление пользовательских данных"
 * 
 * @param string|int $contactId ID контакта (обязательно)
 * @param Bitrix24API $bitrixAPI Экземпляр API
 * @param LocalStorage $localStorage Экземпляр LocalStorage для получения данных из ЛК базы
 * @param Logger $logger Экземпляр логгера
 * @return array|false Результат создания или false при ошибке
 */
function createDeleteDataCard($contactId, $bitrixAPI, $localStorage, $logger)
{
    if (empty($contactId)) {
        $logger->error('Contact ID is required for creating delete data card');
        return false;
    }

    $logger->info('Creating delete data card in smart process', [
        'contact_id' => $contactId,
        'note' => 'company_id and manager_id will be retrieved from LK database'
    ]);

    $result = $bitrixAPI->createDeleteDataCard($contactId, $localStorage);

    if ($result) {
        $logger->info('Delete data card created successfully', [
            'contact_id' => $contactId,
            'card_id' => $result['id'] ?? 'unknown'
        ]);
    } else {
        $logger->error('Failed to create delete data card', [
            'contact_id' => $contactId
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

