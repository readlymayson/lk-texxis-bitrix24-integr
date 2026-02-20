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
require_once __DIR__ . '/../classes/QueueManager.php';

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
    $queueManager = new QueueManager($logger, $config);
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

    $logger->info('=== ADDING EVENT TO QUEUE ===', [
        'event_name' => $eventName,
        'event_type' => getWebhookEventType($eventName),
        'action' => getActionFromEvent($eventName),
        'entity_id' => $entityId,
        'queue_time' => date('Y-m-d H:i:s')
    ]);

    // Добавляем событие в очередь вместо немедленной обработки
    $taskId = $queueManager->push($webhookData);

    if ($taskId) {
        $logger->info('Event successfully added to queue', [
            'event_name' => $eventName,
            'entity_id' => $entityId,
            'task_id' => $taskId,
            'result' => 'QUEUED'
        ]);
        sendResponse(200, ['status' => 'queued', 'event' => $eventName, 'task_id' => $taskId]);
    } else {
        $logger->error('=== FAILED TO ADD EVENT TO QUEUE ===', [
            'event_name' => $eventName,
            'event_type' => getWebhookEventType($eventName),
            'entity_id' => $entityId,
            'result' => 'QUEUE_FAILED'
        ]);
        sendResponse(500, ['error' => 'Failed to queue event', 'event' => $eventName]);
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
 * Отправка HTTP ответа
 */
function sendResponse($statusCode, $data)
{
    http_response_code($statusCode);

    header('Content-Type: application/json; charset=utf-8');
    header('X-Powered-By: Bitrix24-Webhook-Handler');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

