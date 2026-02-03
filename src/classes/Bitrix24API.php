<?php

/**
 * Класс для работы с API Битрикс24
 */
class Bitrix24API
{
    private $config;
    private $logger;

    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Валидация webhook запроса от Битрикс24
     * Битрикс24 может отправлять дополнительную информацию для верификации
     */
    public function validateWebhookRequest($headers, $body)
    {
        try {
            $userAgent = $headers['User-Agent'] ?? $headers['user-agent'] ?? '';
            
            $isValidUserAgent = false;
            if (!empty($userAgent)) {
                $userAgentLower = strtolower($userAgent);
                $validPatterns = [
                    'bitrix24',
                    'bitrix24 webhook',
                    'bitrix24 webhook engine',
                    'bitrix24hook'
                ];
                
                foreach ($validPatterns as $pattern) {
                    if (str_contains($userAgentLower, strtolower($pattern))) {
                        $isValidUserAgent = true;
                        break;
                    }
                }
            }
            
            if (!$isValidUserAgent) {
                $this->logger->warning('Unexpected User-Agent in webhook request', [
                    'user_agent' => $userAgent,
                    'headers' => $headers,
                    'note' => 'Request will be processed, but may not be from Bitrix24'
                ]);
            }

            $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';
            
            $contentTypeLower = strtolower($contentType);
            $contentTypeParts = explode(';', $contentTypeLower);
            $contentTypeBase = trim($contentTypeParts[0]);

            $data = null;
            
            if (str_contains($contentTypeBase, 'application/json')) {
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Invalid JSON in webhook body', [
                        'error' => json_last_error_msg(),
                        'body_preview' => substr($body, 0, 500),
                        'content_type' => $contentType
                    ]);
                    return false;
                }
            } elseif (str_contains($contentTypeBase, 'application/x-www-form-urlencoded')) {
                parse_str($body, $data);
                if (empty($data)) {
                    $this->logger->error('Failed to parse URL-encoded webhook body', [
                        'body_preview' => substr($body, 0, 500),
                        'content_type' => $contentType
                    ]);
                    return false;
                }
            } elseif (empty($contentType) || $contentType === 'UNKNOWN') {
                $this->logger->warning('Content-Type not specified, attempting auto-detection', [
                    'body_preview' => substr($body, 0, 200),
                    'headers' => $headers
                ]);
                
                $jsonData = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData) && !empty($jsonData)) {
                    $data = $jsonData;
                    $this->logger->debug('Auto-detected JSON format');
                } else {
                    parse_str($body, $urlData);
                    if (!empty($urlData)) {
                        $data = $urlData;
                        $this->logger->debug('Auto-detected URL-encoded format');
                    } else {
                        $this->logger->error('Failed to auto-detect webhook body format', [
                            'body_preview' => substr($body, 0, 500),
                            'headers' => $headers
                        ]);
                        return false;
                    }
                }
            } else {
                $this->logger->warning('Unsupported Content-Type in webhook request', [
                    'content_type' => $contentType,
                    'supported_types' => ['application/json', 'application/x-www-form-urlencoded'],
                    'body_preview' => substr($body, 0, 200),
                    'headers' => $headers
                ]);
                return false;
            }

            // Проверка наличия обязательных полей в данных
            if (!is_array($data) || empty($data)) {
                $this->logger->error('Invalid webhook data structure', [
                    'data_type' => gettype($data),
                    'body_preview' => substr($body, 0, 500)
                ]);
                return false;
            }

            // Проверка application_token (опционально, только если настроен)
            $expectedToken = $this->config['bitrix24']['application_token'] ?? '';
            $receivedToken = $data['auth']['application_token'] ?? '';

            if (!empty($expectedToken)) {
                // Если токен настроен, проверяем его
                if (empty($receivedToken)) {
                    $this->logger->warning('Application token expected but not received', [
                        'auth_data' => $data['auth'] ?? [],
                        'data_keys' => array_keys($data)
                    ]);
                    // Не блокируем запрос - возможно используется старый формат без токена
                } elseif ($receivedToken !== $expectedToken) {
                    $this->logger->error('Invalid application token in webhook request', [
                        'expected_token_prefix' => substr($expectedToken, 0, 8) . '...',
                        'received_token_prefix' => substr($receivedToken, 0, 8) . '...',
                        'token_length_match' => strlen($expectedToken) === strlen($receivedToken),
                        'auth_data' => isset($data['auth']) ? array_keys($data['auth']) : []
                    ]);
                    return false;
                }
            } else {
                // Если токен не настроен, просто логируем
                if (!empty($receivedToken)) {
                    $this->logger->debug('Application token received but not configured for validation', [
                        'received_token_prefix' => substr($receivedToken, 0, 8) . '...'
                    ]);
                }
            }

            // Проверка наличия ключевых полей события
            if (empty($data['event'])) {
                $this->logger->error('Missing event field in webhook data', [
                    'data_keys' => array_keys($data),
                    'body_preview' => substr($body, 0, 500)
                ]);
                return false;
            }

            $this->logger->debug('Webhook request validated successfully', [
                'content_type' => $contentType,
                'format' => str_contains($contentTypeBase, 'json') ? 'json' : 'url-encoded',
                'data_keys' => array_keys($data),
                'event' => $data['event'] ?? 'UNKNOWN',
                'application_token_valid' => !empty($expectedToken) ? ($receivedToken === $expectedToken ? 'valid' : 'invalid') : 'not_configured',
                'user_agent_valid' => $isValidUserAgent
            ]);
            return $data;

        } catch (Exception $e) {
            $this->logger->error('Error validating webhook request', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'headers' => $headers,
                'body_length' => strlen($body),
                'body_preview' => substr($body, 0, 500),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }


    /**
     * Получение данных сущности по ID через API
     * Использует только основные поля из конфига маппинга
     */
    public function getEntityData($entityType, $entityId)
    {
        $this->logger->info('Starting entity data retrieval from Bitrix24 API', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        $method = $this->getApiMethodForEntity($entityType, 'get');

        if (!$method) {
            $this->logger->error('Unsupported entity type for API call', ['entity_type' => $entityType]);
            return false;
        }

        $selectFields = $this->getMappedFieldsForEntity($entityType);

        $params = ['id' => $entityId];

        $this->addSmartProcessParams($entityType, $params);

        if (!empty($selectFields)) {
            $params['select'] = $selectFields;
            $this->logger->debug('Using mapped fields for entity data retrieval', [
                'entity_type' => $entityType,
                'select_fields' => $selectFields
            ]);
        }

        $result = $this->makeApiCall($method, $params);

        if ($result && isset($result['result'])) {
            $this->logger->debug('Successfully retrieved entity data from Bitrix24 API', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
        } else {
            $this->logger->warning('Failed to retrieve entity data from Bitrix24 API', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'result_type' => gettype($result),
                'result_keys' => is_array($result) ? array_keys($result) : 'not_array'
            ]);
        }

        return $result;
    }

    /**
     * Получение списка сущностей с фильтром
     */
    public function getEntityList($entityType, $filter = [], $select = [])
    {
        $method = $this->getApiMethodForEntity($entityType, 'list');

        if (!$method) {
            $this->logger->error('Unsupported entity type for list API call', ['entity_type' => $entityType]);
            return false;
        }

        // Нормализация формы фильтра: поддерживаем оба варианта передачи
        // - ['CONTACT_ID' => 1]
        // - ['filter' => ['CONTACT_ID' => 1]]
        if (isset($filter['filter']) && is_array($filter['filter'])) {
            $this->logger->debug('Normalizing nested filter parameter for getEntityList', [
                'entity_type' => $entityType,
                'original_keys' => array_keys($filter)
            ]);
            $filter = $filter['filter'];
        }

        // Если фильтр содержит select, выделяем его как отдельный параметр
        if (isset($filter['select']) && empty($select)) {
            $select = $filter['select'];
            unset($filter['select']);
        }

        $params = [];
        if (!empty($filter)) {
            $params['filter'] = $filter;
        }
        
        if (empty($select)) {
            $select = $this->getMappedFieldsForEntity($entityType);
        }
        
        if (!empty($select)) {
            $params['select'] = $select;
            $this->logger->debug('Using mapped fields for entity list retrieval', [
                'entity_type' => $entityType,
                'select_fields' => $select
            ]);
        }

        $this->addSmartProcessParams($entityType, $params);

        return $this->makeApiCall($method, $params);
    }

    /**
     * Получение связанных контактов компании
     * В Bitrix24 компания может быть связана с контактами через множественную связь
     * 
     * @param int $companyId ID компании
     * @return array|false Массив контактов или false при ошибке
     */
    public function getCompanyContacts($companyId)
    {
        $this->logger->info('Getting company contacts from Bitrix24 API', [
            'company_id' => $companyId
        ]);

        $method = 'crm.company.contact.items.get';
        $params = ['id' => $companyId];

        $result = $this->makeApiCall($method, $params);

        if ($result && isset($result['result'])) {
            $contacts = $result['result'];
            
            $this->logger->debug('Company contacts API response structure', [
                'company_id' => $companyId,
                'result_type' => gettype($contacts),
                'is_array' => is_array($contacts),
                'contacts_count' => is_array($contacts) ? count($contacts) : 0,
                'first_item_keys' => is_array($contacts) && !empty($contacts) && isset($contacts[0]) ? array_keys($contacts[0]) : 'not_array_or_empty'
            ]);
            
            if (is_array($contacts) && !empty($contacts)) {
                $this->logger->debug('Successfully retrieved company contacts', [
                    'company_id' => $companyId,
                    'contacts_count' => count($contacts)
                ]);
                return $contacts;
            } else {
                $this->logger->debug('Company contacts list is empty', [
                    'company_id' => $companyId
                ]);
                return [];
            }
        } else {
            $this->logger->warning('Failed to retrieve company contacts or no contacts found', [
                'company_id' => $companyId,
                'result_type' => gettype($result),
                'result_keys' => is_array($result) ? array_keys($result) : 'not_array'
            ]);
            return false;
        }
    }

    /**
     * Выполнение API запроса к Битрикс24
     */
    private function makeApiCall($method, $params = [])
    {
        $webhookUrl = $this->config['bitrix24']['webhook_url'] ?? '';
        
        // Валидация URL
        if (empty($webhookUrl)) {
            $this->logger->error('Webhook URL is not configured');
            return false;
        }
        
        // Проверка формата URL
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            $this->logger->error('Invalid webhook URL format', [
                'webhook_url' => substr($webhookUrl, 0, 50) . '...'
            ]);
            return false;
        }
        
        // Проверка протокола (должен быть HTTPS)
        if (!str_starts_with(strtolower($webhookUrl), 'https://')) {
            $this->logger->warning('Webhook URL is not using HTTPS', [
                'webhook_url' => substr($webhookUrl, 0, 50) . '...'
            ]);
        }
        
        $url = rtrim($webhookUrl, '/') . '/' . $method . '.json';
        
        // Дополнительная валидация полного URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('Invalid constructed URL', [
                'method' => $method,
                'url' => substr($url, 0, 100) . '...'
            ]);
            return false;
        }

        $postData = json_encode($params, JSON_UNESCAPED_UNICODE);
        
        // Проверка результата json_encode
        if ($postData === false) {
            $this->logger->error('JSON encoding failed in API call', [
                'method' => $method,
                'json_error' => json_last_error_msg(),
                'params_keys' => array_keys($params)
            ]);
            return false;
        }

        $this->logger->debug('Making API call', [
            'method' => $method,
            'url' => preg_replace('/\/[^\/]+\/[^\/]+\//', '/***/', $url), // Скрываем токен в логах
            'params' => $params
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => $this->config['bitrix24']['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '', // Автоматическая декомпрессия
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->logger->error('CURL error in API call', [
                'method' => $method,
                'error' => $error,
                'url' => preg_replace('/\/[^\/]+\/[^\/]+\//', '/***/', $url) // Скрываем токен в логах
            ]);
            return false;
        }

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            // Проверяем, является ли это ошибкой "Not found"
            $isNotFound = false;
            if ($result && isset($result['error_description'])) {
                $errorDesc = strtolower($result['error_description']);
                $isNotFound = (
                    str_contains($errorDesc, 'not found') ||
                    str_contains($errorDesc, 'не найден') ||
                    ($result['error'] === '' && str_contains($errorDesc, 'not found'))
                );
            }
            
            $this->logger->error('API call returned non-200 status code', [
                'method' => $method,
                'http_code' => $httpCode,
                'url' => preg_replace('/\/[^\/]+\/[^\/]+\//', '/***/', $url), // Скрываем токен в логах
                'response' => $response,
                'is_not_found' => $isNotFound
            ]);
            
            // Возвращаем специальное значение для "Not found"
            if ($isNotFound) {
                return ['error' => 'NOT_FOUND', 'http_code' => $httpCode];
            }
            
            return false;
        }

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from API', [
                'method' => $method,
                'response' => $response,
                'json_error' => json_last_error_msg()
            ]);
            return false;
        }

        if (isset($result['error'])) {
            // Проверяем, является ли это ошибкой "Not found"
            $errorDesc = strtolower($result['error_description'] ?? '');
            $isNotFound = (
                str_contains($errorDesc, 'not found') ||
                str_contains($errorDesc, 'не найден') ||
                ($result['error'] === '' && str_contains($errorDesc, 'not found'))
            );
            
            $this->logger->error('API returned error', [
                'method' => $method,
                'error' => $result['error'],
                'error_description' => $result['error_description'] ?? '',
                'is_not_found' => $isNotFound
            ]);
            
            // Возвращаем специальное значение для "Not found"
            if ($isNotFound) {
                return ['error' => 'NOT_FOUND', 'http_code' => $httpCode];
            }
            
            return false;
        }

        $this->logger->debug('API call successful', [
            'method' => $method,
            'result_count' => isset($result['result']) && is_array($result['result']) ? count($result['result']) : (isset($result['result']) ? 1 : 0)
        ]);

        return $result;
    }

    /**
     * Запуск бизнес-процесса для отправки email контакту через Битрикс24
     *
     * @param int|string $contactId ID контакта в Bitrix24
     * @param string $url Строка URL для передачи в бизнес-процесс
     * @return array|false Результат bizproc.workflow.start или false при ошибке
     */
    public function startEmailBusinessProcess($contactId, $url)
    {
        if (empty($contactId)) {
            $this->logger->error('Contact ID is required to start email business process');
            return false;
        }

        if (empty($url) || trim((string)$url) === '') {
            $this->logger->error('URL is required to start email business process', [
                'contact_id' => $contactId
            ]);
            return false;
        }

        $businessProcessId = $this->config['bitrix24']['email_business_process_id'] ?? 0;
        
        if (empty($businessProcessId)) {
            $this->logger->error('Email business process ID is not configured', [
                'contact_id' => $contactId
            ]);
            return false;
        }

        $params = [
            'TEMPLATE_ID' => (int)$businessProcessId,
            'DOCUMENT_TYPE' => ['crm', 'CCrmDocumentContact', 'CONTACT'],
            'DOCUMENT_ID' => ['crm', 'CCrmDocumentContact', 'CONTACT_' . (int)$contactId],
            'PARAMETERS' => [
                'URL' => $url
            ]
        ];

        $this->logger->info('Starting email business process via Bitrix24', [
            'contact_id' => $contactId,
            'business_process_id' => $businessProcessId,
            'url' => $url
        ]);

        $result = $this->makeApiCall('bizproc.workflow.start', $params);

        if ($result && isset($result['result'])) {
            $this->logger->debug('Email business process started successfully', [
            'contact_id' => $contactId,
                'workflow_id' => $result['result'] ?? 'unknown'
            ]);
        } else {
            $this->logger->warning('Failed to start email business process', [
                'contact_id' => $contactId,
                'business_process_id' => $businessProcessId,
                'result' => $result
            ]);
        }

        return $result;
    }

    /**
     * Добавление параметров для смарт-процессов (entityTypeId)
     */
    private function addSmartProcessParams($entityType, &$params)
    {
        if ($entityType === 'smart_process') {
            $smartProcessId = $this->config['bitrix24']['smart_process_id'] ?? '';
            if (!empty($smartProcessId)) {
                $params['entityTypeId'] = $smartProcessId;
                $this->logger->debug('Using smart process entityTypeId', [
                    'entity_type_id' => $smartProcessId
                ]);
            } else {
                $this->logger->warning('Smart process ID not configured in config');
            }
        }
    }

    /**
     * Получение API метода для типа сущности и действия
     */
    private function getApiMethodForEntity($entityType, $action)
    {
        $methods = [
            'contact' => [
                'get' => 'crm.contact.get',
                'list' => 'crm.contact.list',
                'update' => 'crm.contact.update',
                'add' => 'crm.contact.add',
                'delete' => 'crm.contact.delete'
            ],
            'company' => [
                'get' => 'crm.company.get',
                'list' => 'crm.company.list',
                'update' => 'crm.company.update',
                'add' => 'crm.company.add',
                'delete' => 'crm.company.delete'
            ],
            'smart_process' => [
                'get' => 'crm.item.get',
                'list' => 'crm.item.list',
                'update' => 'crm.item.update',
                'add' => 'crm.item.add',
                'delete' => 'crm.item.delete'
            ],
            'user' => [
                'get' => 'user.get',
                'list' => 'user.get',
            ]
        ];

        return $methods[$entityType][$action] ?? false;
    }

    /**
     * Получение списка полей для выборки из конфига маппинга
     */
    private function getMappedFieldsForEntity($entityType)
    {
        $mapping = $this->config['field_mapping'][$entityType] ?? [];

        if (empty($mapping)) {
            $this->logger->debug('No field mapping found for entity type', [
                'entity_type' => $entityType
            ]);
            return [];
        }

        $fields = [];
        // Список служебных ключей, которые не являются именами полей в Bitrix24
        $skipKeys = [
            'lk_client_values',      // Массив допустимых значений
            'lk_delete_values'       // Массив значений для проверки удаления
        ];
        
        foreach ($mapping as $key => $value) {
            // Пропускаем служебные ключи (не являются именами полей)
            if (in_array($key, $skipKeys)) {
                continue;
            }
            
            // Обрабатываем массивы, которые содержат названия полей (например, мессенджеры)
            if (is_array($value)) {
                foreach ($value as $nestedValue) {
                    if (is_string($nestedValue) && !empty($nestedValue)) {
                        $fields[] = $nestedValue;
                    } elseif (is_array($nestedValue)) {
                        foreach ($nestedValue as $deepValue) {
                            if (is_string($deepValue) && !empty($deepValue)) {
                                $fields[] = $deepValue;
                            }
                        }
                    }
                }
                continue;
            }

            // Добавляем все строковые значения из маппинга в SELECT
            // Это имена полей в Bitrix24 (EMAIL, PHONE, NAME, UF_CRM_*, и т.д.)
            // Включая lk_client_field => 'UF_CRM_1765110404000'
            if (is_string($value) && !empty($value)) {
                $fields[] = $value;
            }
        }

        if (!in_array('ID', $fields)) {
            $fields[] = 'ID';
        }

        // Для компаний всегда добавляем CONTACT_ID, если его еще нет
        // Это необходимо для проверки связи компании с контактом
        if ($entityType === 'company' && !in_array('CONTACT_ID', $fields)) {
            // Используем значение из маппинга, если оно есть, иначе добавляем напрямую
            if (isset($mapping['contact_id']) && !empty($mapping['contact_id'])) {
                $fields[] = $mapping['contact_id'];
            } else {
                // Если в маппинге нет contact_id, добавляем CONTACT_ID напрямую
                $fields[] = 'CONTACT_ID';
            }
        }

        $fields = array_values(array_unique($fields));

        $this->logger->debug('Extracted mapped fields for entity', [
            'entity_type' => $entityType,
            'mapped_fields' => $fields,
            'mapping_keys' => array_keys($mapping)
        ]);

        return $fields;
    }

    /**
     * Получение типа сущности из названия события
     */
    public function getEntityTypeFromEvent($eventName)
    {
        $mapping = [
            'ONCRMCONTACT' => 'contact',
            'ONCRMCOMPANY' => 'company',
            'ONCRMDYNAMICITEM' => 'smart_process'
        ];

        foreach ($mapping as $prefix => $type) {
            if (str_starts_with($eventName, $prefix)) {
                return $type;
            }
        }

        return false;
    }

    /**
     * Получение ID корневой папки пользователя на Диске Bitrix24
     * 
     * @return int|false ID папки или false при ошибке
     */
    private function getRootFolderId()
    {
        // Метод 1: disk.folder.getforupload - специальный метод для получения папки для загрузки
        $method1 = 'disk.folder.getforupload';
        $result1 = $this->makeApiCall($method1, []);
        
        if ($result1 && isset($result1['result'])) {
            $folderId = $result1['result']['FOLDER_ID'] ?? $result1['result']['folder_id'] ?? 
                       $result1['result']['ID'] ?? $result1['result']['id'] ?? null;
            if ($folderId) {
                $this->logger->debug('Root folder ID obtained via getforupload', [
                    'method' => $method1,
                    'folder_id' => $folderId,
                    'result_structure' => json_encode($result1['result'], JSON_UNESCAPED_UNICODE)
                ]);
                return $folderId;
            }
        }
        
        // Метод 2: disk.storage.get - получаем хранилище и извлекаем ROOT_FOLDER_ID
        // Пробуем сначала с ID=1, если не работает - получаем список всех хранилищ
        $method2 = 'disk.storage.get';
        
        // Сначала пробуем получить конкретное хранилище
        $result2 = $this->makeApiCall($method2, ['id' => 1]);
        
        // Если не получилось, пробуем получить список хранилищ
        if (!$result2 || !isset($result2['result'])) {
            $result2 = $this->makeApiCall($method2, []);
        }
        
        if ($result2 && isset($result2['result'])) {
            $resultData = $result2['result'];
            $this->logger->debug('disk.storage.get response structure', [
                'result_type' => gettype($resultData),
                'is_array' => is_array($resultData),
                'array_count' => is_array($resultData) ? count($resultData) : 0,
                'is_numeric_array' => is_array($resultData) && (empty($resultData) || array_keys($resultData) === range(0, count($resultData) - 1)),
                'keys' => is_array($resultData) ? array_keys($resultData) : 'not_array',
                'sample' => is_array($resultData) ? json_encode(array_slice($resultData, 0, 10), JSON_UNESCAPED_UNICODE) : json_encode($resultData, JSON_UNESCAPED_UNICODE)
            ]);
            
            $folderId = null;
            
            // Проверяем, является ли result массивом с числовыми индексами (список хранилищ)
            // или ассоциативным массивом/объектом (одно хранилище)
            $isNumericArray = is_array($resultData) && !empty($resultData) && array_keys($resultData) === range(0, count($resultData) - 1);
            
            if ($isNumericArray) {
                // Если result - это массив хранилищ (числовые индексы)
                foreach ($resultData as $index => $storage) {
                    if (is_array($storage)) {
                        // Пробуем разные варианты ключей
                        $folderId = $storage['ROOT_FOLDER_ID'] ?? 
                                   $storage['root_folder_id'] ?? 
                                   $storage['ROOT_FOLDER']['ID'] ?? 
                                   $storage['root_folder']['id'] ?? null;
                        
                        // Если не нашли, логируем структуру для отладки
                        if (!$folderId) {
                            $this->logger->debug('Storage item structure', [
                                'index' => $index,
                                'storage_keys' => array_keys($storage),
                                'has_root_folder' => isset($storage['ROOT_FOLDER']),
                                'has_root_folder_id' => isset($storage['ROOT_FOLDER_ID']),
                                'storage_sample' => json_encode(array_slice($storage, 0, 15), JSON_UNESCAPED_UNICODE)
                            ]);
                        } else {
                            $this->logger->debug('Found root folder ID in storage', [
                                'storage_index' => $index,
                                'storage_id' => $storage['ID'] ?? $storage['id'] ?? 'unknown',
                                'folder_id' => $folderId
                            ]);
                            break;
                        }
                    }
                }
            } elseif (is_array($resultData) && empty($resultData)) {
                $this->logger->warning('disk.storage.get returned empty array');
            } else {
                // Если result - это объект хранилища (ассоциативный массив)
                // Пробуем разные варианты получения ROOT_FOLDER_ID
                $folderId = $resultData['ROOT_FOLDER_ID'] ?? 
                           $resultData['root_folder_id'] ?? null;
                
                // Если не нашли напрямую, проверяем вложенный объект ROOT_FOLDER
                if (!$folderId && isset($resultData['ROOT_FOLDER'])) {
                    $rootFolder = $resultData['ROOT_FOLDER'];
                    if (is_array($rootFolder)) {
                        $folderId = $rootFolder['ID'] ?? $rootFolder['id'] ?? null;
                    }
                }
                
                // Если не нашли ROOT_FOLDER_ID, пробуем использовать ROOT_OBJECT_ID
                // В некоторых версиях Bitrix24 ROOT_OBJECT_ID может быть ID корневой папки
                if (!$folderId && isset($resultData['ROOT_OBJECT_ID'])) {
                    $folderId = $resultData['ROOT_OBJECT_ID'];
                    $this->logger->debug('Using ROOT_OBJECT_ID as folder ID', [
                        'root_object_id' => $folderId
                    ]);
                }
                
                // Логируем структуру для отладки, если не нашли
                if (!$folderId) {
                    $this->logger->debug('Storage object structure', [
                        'storage_keys' => is_array($resultData) ? array_keys($resultData) : 'not_array',
                        'has_root_folder' => isset($resultData['ROOT_FOLDER']),
                        'has_root_folder_id' => isset($resultData['ROOT_FOLDER_ID']),
                        'has_root_object_id' => isset($resultData['ROOT_OBJECT_ID']),
                        'root_object_id' => $resultData['ROOT_OBJECT_ID'] ?? 'not_set',
                        'root_folder_type' => isset($resultData['ROOT_FOLDER']) ? gettype($resultData['ROOT_FOLDER']) : 'not_set',
                        'root_folder_keys' => (isset($resultData['ROOT_FOLDER']) && is_array($resultData['ROOT_FOLDER'])) 
                            ? array_keys($resultData['ROOT_FOLDER']) : 'not_array',
                        'storage_sample' => json_encode(array_slice(is_array($resultData) ? $resultData : [], 0, 20), JSON_UNESCAPED_UNICODE)
                    ]);
                }
            }
            
            if ($folderId) {
                $this->logger->debug('Root folder ID obtained via storage.get', [
                    'method' => $method2,
                    'folder_id' => $folderId
                ]);
                return $folderId;
            } else {
                // Детальное логирование для отладки структуры ответа
                $debugInfo = [
                    'result_type' => gettype($resultData),
                    'is_array' => is_array($resultData),
                    'array_count' => is_array($resultData) ? count($resultData) : 0
                ];
                
                if (is_array($resultData) && !empty($resultData)) {
                    // Логируем информацию о каждом элементе массива
                    $debugInfo['items_info'] = [];
                    foreach (array_slice($resultData, 0, 3) as $idx => $item) {
                        $itemInfo = [
                            'index' => $idx,
                            'type' => gettype($item),
                            'is_array' => is_array($item)
                        ];
                        if (is_array($item)) {
                            $itemInfo['keys'] = array_keys($item);
                            $itemInfo['has_root_folder_id'] = isset($item['ROOT_FOLDER_ID']);
                            $itemInfo['has_root_folder'] = isset($item['ROOT_FOLDER']);
                            $itemInfo['sample'] = json_encode(array_slice($item, 0, 20), JSON_UNESCAPED_UNICODE);
                        } else {
                            $itemInfo['value'] = $item;
                        }
                        $debugInfo['items_info'][] = $itemInfo;
                    }
                } else {
                    $debugInfo['result_value'] = is_array($resultData) ? 'empty_array' : json_encode($resultData, JSON_UNESCAPED_UNICODE);
                }
                
                $this->logger->warning('disk.storage.get returned result but ROOT_FOLDER_ID not found', $debugInfo);
            }
        }
        
        // Метод 3: disk.folder.getchildren - получаем список дочерних папок корневой папки
        // Пробуем с ID=0 (корневая папка) или без параметров
        $method3 = 'disk.folder.getchildren';
        $result3 = $this->makeApiCall($method3, ['id' => 0]);
        
        // Если не получилось с ID=0, пробуем без параметров
        if (!$result3 || !isset($result3['result'])) {
            $result3 = $this->makeApiCall($method3, []);
        }
        
        if ($result3 && isset($result3['result'])) {
            $this->logger->debug('disk.folder.getchildren response', [
                'result_type' => gettype($result3['result']),
                'is_array' => is_array($result3['result']),
                'has_items' => is_array($result3['result']) && !empty($result3['result'])
            ]);
            // Этот метод возвращает список папок, но не ID корневой папки напрямую
            // Поэтому используем его только для проверки доступности API
        }
        
        $this->logger->warning('Failed to get root folder ID using all methods', [
            'method1_result' => isset($result1) ? (is_array($result1) ? 'array' : gettype($result1)) : 'not_called',
            'method2_result' => isset($result2) ? (is_array($result2) ? 'array' : gettype($result2)) : 'not_called',
            'method3_result' => isset($result3) ? (is_array($result3) ? 'array' : gettype($result3)) : 'not_called'
        ]);
        return false;
    }

    /**
     * Загрузка файла в Bitrix24
     *
     * @param string $filePath Путь к локальному файлу
     * @param int $folderId ID папки на Диске Bitrix24 (опционально, будет получен автоматически)
     * @return array|false Результат загрузки с ID файла или false при ошибке
     */
    public function uploadFile($filePath, $folderId = null)
    {
        // Проверяем существование файла
        if (!file_exists($filePath)) {
            $this->logger->error('File not found for upload', ['file_path' => $filePath]);
            return false;
        }

        // Проверяем, что это файл, а не директория
        if (!is_file($filePath)) {
            $this->logger->error('Path is not a file', ['file_path' => $filePath]);
            return false;
        }

        // Проверяем права доступа на чтение
        if (!is_readable($filePath)) {
            $this->logger->error('File is not readable', ['file_path' => $filePath]);
            return false;
        }

        $fileSize = filesize($filePath);

        // Проверяем размер файла (максимум 100MB для безопасности)
        $maxFileSize = 100 * 1024 * 1024; // 100MB
        if ($fileSize > $maxFileSize) {
            $this->logger->error('File too large for upload', [
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'max_size' => $maxFileSize
            ]);
            return false;
        }

        // Проверяем, что файл не пустой
        if ($fileSize === 0) {
            $this->logger->error('File is empty', ['file_path' => $filePath]);
            return false;
        }

        // Определяем MIME тип для логирования
        $mimeType = 'unknown';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
        }

        // Читаем содержимое файла с проверкой ошибок
        $fileContent = file_get_contents($filePath);
        if ($fileContent === false) {
            $this->logger->error('Failed to read file content', [
                'file_path' => $filePath,
                'file_size' => $fileSize
            ]);
            return false;
        }

        $originalFileName = basename($filePath);

        $this->logger->debug('Uploading file to Bitrix24', [
            'file_path' => $filePath,
            'file_size' => $fileSize,
            'mime_type' => $mimeType,
            'original_name' => $originalFileName,
            'folder_id' => $folderId
        ]);

        // Если folderId не указан, получаем ID корневой папки
        if ($folderId === null) {
            $folderId = $this->getRootFolderId();
            if ($folderId === false) {
                $this->logger->warning('Cannot get root folder ID, will try alternative upload method');
                // Не прерываем выполнение, попробуем альтернативный метод загрузки
                $folderId = null;
            }
        }

        // Добавляем timestamp к имени файла, чтобы избежать конфликтов при повторной загрузке
        $pathInfo = pathinfo($originalFileName);

        // Определяем расширение файла более надежно
        $extension = '';
        if (!empty($pathInfo['extension'])) {
            $extension = $pathInfo['extension'];
        } else {
            // Для файлов без расширения пытаемся определить тип по MIME
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mimeType = finfo_file($finfo, $filePath);
                finfo_close($finfo);

                // Простое маппинг MIME типов на расширения
                $mimeToExt = [
                    'application/pdf' => 'pdf',
                    'application/msword' => 'doc',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                    'application/vnd.ms-excel' => 'xls',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                    'application/vnd.ms-powerpoint' => 'ppt',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                    'text/plain' => 'txt',
                    'text/csv' => 'csv',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'application/zip' => 'zip',
                    'application/x-rar-compressed' => 'rar',
                    'application/x-7z-compressed' => '7z'
                ];

                if (isset($mimeToExt[$mimeType])) {
                    $extension = $mimeToExt[$mimeType];
                }
            }
        }

        // Формируем безопасное имя файла
        $safeFileName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ_\-\.]/u', '_', $pathInfo['filename']);
        $fileName = $safeFileName . '_' . time() . ($extension ? '.' . $extension : '');
        
        // Если folderId не получен, пробуем использовать ROOT_OBJECT_ID из storage
        if ($folderId === null || $folderId === false) {
            // Пробуем получить ROOT_OBJECT_ID из storage
            $storageResult = $this->makeApiCall('disk.storage.get', ['id' => 1]);
            if ($storageResult && isset($storageResult['result']['ROOT_OBJECT_ID'])) {
                $folderId = $storageResult['result']['ROOT_OBJECT_ID'];
                $this->logger->debug('Using ROOT_OBJECT_ID as folder ID for upload', [
                    'root_object_id' => $folderId
                ]);
            }
        }
        
        // Если folderId все еще не получен, пробуем альтернативные методы
        if ($folderId === null || $folderId === false) {
            $this->logger->debug('Trying alternative upload methods since folder ID not available');
            
            // Метод 1: disk.file.upload (без указания папки)
            $method = 'disk.file.upload';
            $params = [
                'data' => [
                    'NAME' => $fileName
                ],
                'fileContent' => base64_encode($fileContent)
            ];
            
            $this->logger->debug('Attempting file upload (method 1: disk.file.upload)', [
                'method' => $method,
                'file_name' => isset($fileName) ? $fileName : $originalFileName,
                'file_size' => $fileSize
            ]);
            
            $result = $this->makeApiCall($method, $params);
            
            // Если не сработало, пробуем метод 2
            if ($result === false || (isset($result['error']) && str_contains($result['error'], 'NOT_FOUND'))) {
                $this->logger->debug('Method 1 failed, trying method 2: disk.file.uploadfile');
                
                // Метод 2: disk.file.uploadfile (с ID хранилища)
                $method = 'disk.file.uploadfile';
                $params = [
                    'id' => 1, // ID хранилища
                    'data' => [
                        'NAME' => $fileName
                    ],
                    'fileContent' => base64_encode($fileContent)
                ];
                
                $result = $this->makeApiCall($method, $params);
            }
        } else {
            // Используем disk.folder.uploadfile для загрузки файла
            $method = 'disk.folder.uploadfile';
            
            // Bitrix24 требует передавать файл через fileContent в base64
            // Формат: id - ID папки, data[NAME] - имя файла, fileContent - содержимое в base64
            $params = [
                'id' => $folderId,
                'data' => [
                    'NAME' => $fileName
                ],
                'fileContent' => base64_encode($fileContent)
            ];

            $this->logger->debug('Attempting file upload to Bitrix24', [
                'method' => $method,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'folder_id' => $folderId,
                'params_keys' => array_keys($params)
            ]);

            $result = $this->makeApiCall($method, $params);
            
            // Если загрузка не удалась из-за ошибки "файл уже существует", 
            // это не критично - файл уже загружен, можно использовать существующий
            if (isset($result['error']) && str_contains($result['error_description'] ?? '', 'уже есть')) {
                $this->logger->info('File already exists in Bitrix24, this is acceptable', [
                    'file_name' => $fileName,
                    'error' => $result['error'] ?? 'unknown'
                ]);
                // Продолжаем обработку - возможно, в ответе есть информация о существующем файле
                // Если нет, вернем false и попробуем альтернативный метод
            } elseif ($result === false || (isset($result['error']) && (str_contains($result['error'], 'NOT_FOUND') || str_contains($result['error'], 'ERROR')))) {
                $this->logger->warning('Primary upload method failed, trying alternative', [
                    'error' => $result['error'] ?? 'unknown',
                    'error_description' => $result['error_description'] ?? ''
                ]);
                
                // Альтернативный метод: disk.file.uploadfile (загрузка в корень хранилища)
                $altMethod = 'disk.file.uploadfile';
                $altParams = [
                    'id' => 1, // ID хранилища (обычно 1 для личного)
                    'data' => [
                        'NAME' => $fileName
                    ],
                    'fileContent' => base64_encode($fileContent)
                ];
                
                $this->logger->debug('Trying alternative upload method', [
                    'method' => $altMethod,
                    'storage_id' => 1
                ]);
                
                $result = $this->makeApiCall($altMethod, $altParams);
            }
        }

        $this->logger->debug('File upload API response', [
            'file_path' => $filePath,
            'has_result' => isset($result['result']),
            'result_type' => gettype($result),
            'result_keys' => is_array($result) ? array_keys($result) : 'not_array',
            'result_structure' => is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : 'not_array'
        ]);

        if ($result && isset($result['result'])) {
            // Для поля типа "Ссылка" нужен ID объекта на диске, а не FILE_ID
            // ID объекта на диске используется для создания внутренней ссылки
            $diskObjectId = null;
            $fileId = null;
            
            // Сначала получаем ID объекта на диске (для ссылки)
            if (isset($result['result']['ID'])) {
                $diskObjectId = $result['result']['ID'];
            } elseif (isset($result['result']['id'])) {
                $diskObjectId = $result['result']['id'];
            }
            
            // Также сохраняем FILE_ID для совместимости
            if (isset($result['result']['FILE_ID'])) {
                $fileId = $result['result']['FILE_ID'];
            } elseif (isset($result['result']['file_id'])) {
                $fileId = $result['result']['file_id'];
            }
            
            // Для поля типа "Ссылка" используем ID объекта на диске
            // Формат: disk_file_<ID> или просто ID
            $linkId = $diskObjectId;
            
            if ($linkId) {
                // Формируем внутреннюю ссылку на файл в формате Bitrix24
                $internalLink = $this->getInternalFileLink($diskObjectId);
                
                $this->logger->info('File uploaded successfully', [
                    'file_path' => $filePath,
                    'disk_object_id' => $diskObjectId,
                    'file_id' => $fileId,
                    'link_id' => $linkId,
                    'internal_link' => $internalLink,
                    'file_name' => $fileName,
                    'file_size' => $fileSize
                ]);
                return [
                    'id' => $linkId,  // ID объекта на диске для ссылки
                    'internal_link' => $internalLink,  // Полная внутренняя ссылка
                    'disk_object_id' => $diskObjectId,
                    'file_id' => $fileId,
                    'name' => $fileName,
                    'size' => $fileSize
                ];
            } else {
                $this->logger->warning('File upload response received but disk object ID not found', [
                    'file_path' => $filePath,
                    'result_structure' => json_encode($result['result'], JSON_UNESCAPED_UNICODE)
                ]);
            }
        } elseif ($result && isset($result['error'])) {
            $this->logger->error('Bitrix24 API returned error during file upload', [
                'file_path' => $filePath,
                'error' => $result['error'],
                'error_description' => $result['error_description'] ?? '',
                'error_exclamation' => $result['error_exclamation'] ?? ''
            ]);
        }

        $this->logger->error('Failed to upload file', [
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'result' => $result,
            'result_type' => gettype($result)
        ]);
        return false;
    }

    /**
     * Получение internal_link для файла по его ID
     * 
     * @param int $fileId ID файла (может быть disk_object_id или file_id)
     * @return string|null Внутренняя ссылка на файл или null
     */
    private function getFileInternalLink($fileId)
    {
        // Если fileId уже в формате disk_file_XXX, извлекаем ID
        if (is_string($fileId) && strpos($fileId, 'disk_file_') === 0) {
            $fileId = (int)str_replace('disk_file_', '', $fileId);
        }
        
        // Используем fileId как objectId для формирования ссылки
        return $this->getInternalFileLink((int)$fileId);
    }

    /**
     * Формирование внутренней ссылки на файл в Bitrix24
     * 
     * @param int $objectId ID объекта на диске
     * @return string Внутренняя ссылка на файл
     */
    private function getInternalFileLink($objectId)
    {
        $webhookUrl = $this->config['bitrix24']['webhook_url'] ?? '';
        
        // Извлекаем домен из webhook URL
        // Формат: https://domain/rest/user/webhook/...
        $domain = '';
        if (preg_match('#https?://([^/]+)#', $webhookUrl, $matches)) {
            $domain = $matches[1];
        }
        
        if (empty($domain)) {
            $this->logger->warning('Cannot extract domain from webhook URL for internal link', [
                'webhook_url' => substr($webhookUrl, 0, 50) . '...'
            ]);
            // Возвращаем формат без домена, Bitrix24 может обработать
            return "bitrix/tools/disk/focus.php?objectId={$objectId}&cmd=show&action=showObjectInGrid&ncc=1";
        }
        
        // Формируем полную внутреннюю ссылку
        return "https://{$domain}/bitrix/tools/disk/focus.php?objectId={$objectId}&cmd=show&action=showObjectInGrid&ncc=1";
    }

    /**
     * Создание элемента смарт-процесса
     * 
     * @param int $entityTypeId ID смарт-процесса в Bitrix24
     * @param array $fields Поля для создания элемента
     * @return array|false Результат создания или false при ошибке
     */
    public function addSmartProcessItem($entityTypeId, $fields = [])
    {
        $this->logger->debug('Creating smart process item', [
            'entity_type_id' => $entityTypeId
        ]);

        $method = 'crm.item.add';
        $params = [
            'entityTypeId' => $entityTypeId,
            'fields' => $fields
        ];

        $result = $this->makeApiCall($method, $params);

        if ($result && isset($result['result']['item'])) {
            $itemId = $result['result']['item']['id'] ?? null;
            $this->logger->debug('Smart process item created successfully', [
                'entity_type_id' => $entityTypeId,
                'item_id' => $itemId
            ]);
            return $result['result']['item'];
        } else {
            $this->logger->error('Failed to create smart process item', [
                'entity_type_id' => $entityTypeId,
                'result' => $result
            ]);
            return false;
        }
    }

    /**
     * Создание карточки проекта в смарт-процессе
     *
     * Логика перенесена из скрипта test_project_creation.php:
     * - подтягиваем mapping из конфига
     * - принимает значения полей из формы (без автоподстановок списков)
     * - при необходимости подтягивает manager через локальное хранилище/Bitrix24, если не передан
     * - при необходимости загружает файлы и использует internal_link
     * - вызывает addSmartProcessItem
     *
     * @param string|int $contactId ID контакта (обязательно)
     * @param array $formFields значения полей формы по ключам маппинга (кроме contact_id)
     * @param int|array|null $fileId ID уже загруженного файла или массив ID файлов (если есть)
     * @param string|array|null $filePath путь к файлу для загрузки или массив путей к файлам (если нужно загрузить)
     * @param object|null $localStorage экземпляр LocalStorage для получения данных из ЛК базы
     * @return array|false
     */
    public function createProjectCard($contactId, array $formFields = [], $fileId = null, $filePath = null, $localStorage = null)
    {
        $smartProcessId = $this->config['bitrix24']['smart_process_id'] ?? '';
        if (empty($smartProcessId)) {
            $this->logger->warning('Smart process ID for projects not configured');
            return false;
        }

        $mapping = $this->config['field_mapping']['smart_process'] ?? [];
        if (empty($mapping)) {
            $this->logger->warning('Field mapping for project smart process not configured');
            return false;
        }

        if (empty($contactId)) {
            $this->logger->error('Contact ID is required for creating project card');
            return false;
        }

        // Получаем данные из локального хранилища
        $managerId = null;
        $companyId = null;
        if ($localStorage !== null) {
            $contact = $localStorage->getContact($contactId);

            if ($contact) {
                // Получаем manager_id из контакта
                if (!empty($contact['manager_id'])) {
                    $managerId = $contact['manager_id'];
                    $this->logger->debug('Manager ID found in local storage', [
                        'contact_id' => $contactId,
                        'manager_id' => $managerId
                    ]);
                }

                // Получаем company_id из контакта
                if (!empty($contact['company'])) {
                    $companyId = $contact['company'];
                    $this->logger->debug('Company ID found in local storage', [
                        'contact_id' => $contactId,
                        'company_id' => $companyId
                    ]);
                }
            }
        }

        $projectFields = [];

        // Обязательные привязки
        if (!empty($mapping['client_id'])) {
            $projectFields[$mapping['client_id']] = $contactId;
        }

        // Компания: если не передана, пробуем подтянуть
        if (!isset($projectFields[$mapping['company_id'] ?? '']) && !empty($companyId) && !empty($mapping['company_id'])) {
            $projectFields[$mapping['company_id']] = $companyId;
        }

        // Значения из формы (кроме contact_id): ожидаем ключи по маппингу (organization_name, object_name, system_types, location и т.д.)
        foreach ($mapping as $fieldKey => $bitrixField) {
            if ($fieldKey === 'client_id') {
                continue;
            }
            // company_id заполнится ниже, если не пришёл из формы
            if ($fieldKey === 'company_id' && empty($formFields[$fieldKey])) {
                continue;
            }
            // manager_id заполнится ниже, если не пришёл из формы
            if ($fieldKey === 'manager_id' && empty($formFields[$fieldKey])) {
                continue;
            }
            if (array_key_exists($fieldKey, $formFields) && $formFields[$fieldKey] !== null && $formFields[$fieldKey] !== '') {
                $projectFields[$bitrixField] = $formFields[$fieldKey];
            }
        }

        // Менеджер: если не передан, пробуем подтянуть
        if (!isset($projectFields[$mapping['manager_id'] ?? '']) && !empty($managerId) && !empty($mapping['manager_id'])) {
            $projectFields[$mapping['manager_id']] = $managerId;
        }

        // Файлы "Перечень оборудования" (множественное поле)
        $equipmentFileLinks = [];
        if (!empty($mapping['equipment_list'])) {
            // Обрабатываем массив fileId или одиночное значение
            $fileIds = [];
            if (!empty($fileId)) {
                $fileIds = is_array($fileId) ? $fileId : [$fileId];
            }
            
            // Обрабатываем массив filePath или одиночное значение
            $filePaths = [];
            if (!empty($filePath)) {
                $filePaths = is_array($filePath) ? $filePath : [$filePath];
            }
            
            // Используем уже загруженные файлы (fileId)
            foreach ($fileIds as $id) {
                $finalFileId = (int)$id;
                if ($finalFileId > 0) {
                    $this->logger->debug('Using provided file ID for equipment list', [
                        'file_id' => $finalFileId
                    ]);
                    // Получаем internal_link для файла
                    $internalLink = $this->getFileInternalLink($finalFileId);
                    if ($internalLink) {
                        $equipmentFileLinks[] = $internalLink;
                    } else {
                        $equipmentFileLinks[] = 'disk_file_' . $finalFileId;
                    }
                }
            }
            
            // Загружаем новые файлы (filePath)
            foreach ($filePaths as $path) {
                if (!empty($path) && file_exists($path)) {
                    $this->logger->debug('Uploading equipment list file', [
                        'file_path' => $path,
                        'file_size' => filesize($path)
                    ]);
                    $uploadResult = $this->uploadFile($path);
                    if (is_array($uploadResult)) {
                        $finalFileId = $uploadResult['id'] ?? null;
                        $internalLink = $uploadResult['internal_link'] ?? null;
                        $this->logger->debug('Upload result', [
                            'file_id' => $finalFileId,
                            'internal_link' => $internalLink
                        ]);
                        if ($internalLink) {
                            $equipmentFileLinks[] = $internalLink;
                        } elseif ($finalFileId) {
                            $equipmentFileLinks[] = 'disk_file_' . $finalFileId;
                        }
                    } else {
                        $this->logger->error('Upload file failed', [
                            'file_path' => $path,
                            'result' => $uploadResult
                        ]);
                    }
                }
            }
            
            // Устанавливаем значение поля equipment_list
            if (!empty($equipmentFileLinks)) {
                // Bitrix24 для множественного поля типа "Ссылка" ожидает массив ссылок
                if (count($equipmentFileLinks) === 1) {
                    // Если один файл, передаем как строку (для обратной совместимости)
                    $projectFields[$mapping['equipment_list']] = $equipmentFileLinks[0];
                } else {
                    // Если несколько файлов, передаем как массив
                    $projectFields[$mapping['equipment_list']] = $equipmentFileLinks;
                }
            }
        }

        $this->logger->info('Prepared project fields for smart process creation', [
            'contact_id' => $contactId,
            'smart_process_id' => $smartProcessId,
            'has_manager' => !empty($managerId),
            'has_company' => !empty($companyId),
            'fields_keys' => array_keys($projectFields)
        ]);

        return $this->addSmartProcessItem($smartProcessId, $projectFields);
    }

    /**
     * Создание карточки в смарт-процессе "Изменение данных в ЛК"
     * 
     * @param string|int $contactId ID контакта (обязательно)
     * @param array $additionalData Дополнительные данные (new_email, new_phone, и т.д.)
     * @param object|null $localStorage Экземпляр LocalStorage для получения данных из ЛК базы
     * @return array|false Результат создания или false при ошибке
     */
    public function createChangeDataCard($contactId, $additionalData = [], $localStorage = null)
    {
        $smartProcessId = $this->config['bitrix24']['smart_process_change_data_id'] ?? '';
        
        if (empty($smartProcessId)) {
            $this->logger->warning('Smart process ID for change data not configured');
            return false;
        }

        $mapping = $this->config['field_mapping']['smart_process_change_data'] ?? [];
        
        if (empty($mapping)) {
            $this->logger->warning('Field mapping for change data smart process not configured');
            return false;
        }

        $fields = [];
        
        // Обязательное поле - contact_id
        if (empty($contactId)) {
            $this->logger->error('Contact ID is required for creating change data card');
            return false;
        }
        
        if (!empty($mapping['contact_id'])) {
            $fields[$mapping['contact_id']] = $contactId;
        }
        
        // Получаем данные из локального хранилища, если передан LocalStorage
        if ($localStorage !== null) {
            $contact = $localStorage->getContact($contactId);
            
            if ($contact) {
                // Получаем company_id из контакта
                if (!empty($contact['company']) && !empty($mapping['company_id'])) {
                    $fields[$mapping['company_id']] = $contact['company'];
                    $this->logger->debug('Retrieved company_id from local storage', [
                        'contact_id' => $contactId,
                        'company_id' => $contact['company']
                    ]);
                }
                
                // Получаем manager_id из контакта (локальное хранилище)
                if (!empty($contact['manager_id']) && !empty($mapping['manager_id'])) {
                    $fields[$mapping['manager_id']] = $contact['manager_id'];
                    $this->logger->debug('Retrieved manager_id from local storage', [
                        'contact_id' => $contactId,
                        'manager_id' => $contact['manager_id']
                    ]);
                }
            } else {
                $this->logger->warning('Contact not found in local storage', [
                    'contact_id' => $contactId
                ]);
            }
        }

        // Добавляем дополнительные данные (new_email, new_phone, и т.д.)
        if (isset($additionalData['new_email']) && !empty($mapping['new_email'])) {
            $fields[$mapping['new_email']] = $additionalData['new_email'];
        }
        
        if (isset($additionalData['new_phone']) && !empty($mapping['new_phone'])) {
            $fields[$mapping['new_phone']] = $additionalData['new_phone'];
        }
        
        if (isset($additionalData['change_reason_personal']) && !empty($mapping['change_reason_personal'])) {
            $fields[$mapping['change_reason_personal']] = $additionalData['change_reason_personal'];
        }

        if (isset($additionalData['new_company_name']) && !empty($mapping['new_company_name'])) {
            $fields[$mapping['new_company_name']] = $additionalData['new_company_name'];
        }
        
        if (isset($additionalData['new_company_website']) && !empty($mapping['new_company_website'])) {
            $fields[$mapping['new_company_website']] = $additionalData['new_company_website'];
        }
        
        if (isset($additionalData['new_company_inn']) && !empty($mapping['new_company_inn'])) {
            $fields[$mapping['new_company_inn']] = $additionalData['new_company_inn'];
        }
        
        if (isset($additionalData['new_company_phone']) && !empty($mapping['new_company_phone'])) {
            $fields[$mapping['new_company_phone']] = $additionalData['new_company_phone'];
        }
        
        if (isset($additionalData['change_reason_company']) && !empty($mapping['change_reason_company'])) {
            $fields[$mapping['change_reason_company']] = $additionalData['change_reason_company'];
        }

        $this->logger->debug('Prepared fields for change data card', [
            'smart_process_id' => $smartProcessId,
            'contact_id' => $contactId,
            'fields' => $fields
        ]);

        return $this->addSmartProcessItem($smartProcessId, $fields);
    }

    /**
     * Создание карточки в смарт-процессе "Удаление пользовательских данных"
     * 
     * @param string|int $contactId ID контакта (обязательно)
     * @param object|null $localStorage Экземпляр LocalStorage для получения данных из ЛК базы
     * @return array|false Результат создания или false при ошибке
     */
    public function createDeleteDataCard($contactId, $localStorage = null)
    {
        $smartProcessId = $this->config['bitrix24']['smart_process_delete_data_id'] ?? '';
        
        if (empty($smartProcessId)) {
            $this->logger->warning('Smart process ID for delete data not configured');
            return false;
        }

        $mapping = $this->config['field_mapping']['smart_process_delete_data'] ?? [];
        
        if (empty($mapping)) {
            $this->logger->warning('Field mapping for delete data smart process not configured');
            return false;
        }

        $fields = [];
        
        // Обязательное поле - contact_id
        if (empty($contactId)) {
            $this->logger->error('Contact ID is required for creating delete data card');
            return false;
        }
        
        if (!empty($mapping['contact_id'])) {
            $fields[$mapping['contact_id']] = $contactId;
        }
        
        // Получаем данные из локального хранилища, если передан LocalStorage
        if ($localStorage !== null) {
            $contact = $localStorage->getContact($contactId);
            
            if ($contact) {
                // Получаем company_id из контакта
                if (!empty($contact['company']) && !empty($mapping['company_id'])) {
                    $fields[$mapping['company_id']] = $contact['company'];
                    $this->logger->debug('Retrieved company_id from local storage', [
                        'contact_id' => $contactId,
                        'company_id' => $contact['company']
                    ]);
                }
                
                // Получаем manager_id из контакта (локальное хранилище)
                if (!empty($contact['manager_id']) && !empty($mapping['manager_id'])) {
                    $fields[$mapping['manager_id']] = $contact['manager_id'];
                    $this->logger->debug('Retrieved manager_id from local storage', [
                        'contact_id' => $contactId,
                        'manager_id' => $contact['manager_id']
                    ]);
                }
            } else {
                $this->logger->warning('Contact not found in local storage', [
                    'contact_id' => $contactId
                ]);
            }
        }

        $this->logger->debug('Prepared fields for delete data card', [
            'smart_process_id' => $smartProcessId,
            'contact_id' => $contactId,
            'fields' => $fields
        ]);

        return $this->addSmartProcessItem($smartProcessId, $fields);
    }

    /**
     * Получение полей типа списка смарт-процесса для проекта
     * 
     * @param int|null $entityTypeId ID смарт-процесса (если не указан, берется из конфига)
     * @return array|false Массив с полями типа списка и их значениями или false при ошибке
     * 
     * Формат возвращаемых данных:
     * [
     *   'field_id' => [
     *     'name' => 'Название поля',
     *     'type' => 'enum',
     *     'values' => [
     *       'ID' => 'Название значения',
     *       ...
     *     ]
     *   ],
     *   ...
     * ]
     */
    public function getSmartProcessListFields($entityTypeId = null)
    {
        // Если entityTypeId не указан, берем из конфига
        if ($entityTypeId === null) {
            $entityTypeId = $this->config['bitrix24']['smart_process_id'] ?? '';
        }
        
        if (empty($entityTypeId)) {
            $this->logger->error('Smart process ID is not configured');
            return false;
        }

        $this->logger->debug('Getting smart process list fields', [
            'entity_type_id' => $entityTypeId
        ]);

        // Получаем все поля смарт-процесса (включая пользовательские поля)
        // Используем crm.item.fields для получения всех полей элемента смарт-процесса
        $method = 'crm.item.fields';
        $params = [
            'entityTypeId' => $entityTypeId
        ];

        $result = $this->makeApiCall($method, $params);

        if (!$result || !isset($result['result'])) {
            $this->logger->error('Failed to get smart process fields', [
                'entity_type_id' => $entityTypeId,
                'result' => $result
            ]);
            return false;
        }

        // Структура ответа зависит от метода API
        // crm.item.fields возвращает поля напрямую в result
        // crm.type.fields возвращает поля в result.fields
        $resultData = $result['result'];
        $fields = $resultData;
        
        // Если это crm.type.fields, поля могут быть в result.fields
        if (isset($resultData['fields']) && is_array($resultData['fields'])) {
            $fields = $resultData['fields'];
        }
        
        // Детальное логирование структуры ответа для отладки
        $this->logger->debug('Smart process fields API response structure', [
            'entity_type_id' => $entityTypeId,
            'method' => $method,
            'result_keys' => array_keys($resultData),
            'has_fields_key' => isset($resultData['fields']),
            'fields_count' => is_array($fields) ? count($fields) : 0,
            'fields_type' => gettype($fields),
            'is_array' => is_array($fields),
            'sample_fields' => is_array($fields) ? array_slice(array_keys($fields), 0, 10) : 'not_array'
        ]);
        
        if (!is_array($fields)) {
            $this->logger->error('Fields data is not an array', [
                'entity_type_id' => $entityTypeId,
                'fields_type' => gettype($fields)
            ]);
            return false;
        }
        
        $listFields = [];

        // Фильтруем поля типа списка (enum, list)
        foreach ($fields as $fieldId => $fieldData) {
            if (!is_array($fieldData)) {
                continue;
            }
            
            $fieldType = $fieldData['type'] ?? '';
            $fieldTitle = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
            
            // Детальное логирование для каждого поля
            $this->logger->debug('Processing field', [
                'field_id' => $fieldId,
                'field_title' => $fieldTitle,
                'field_type' => $fieldType,
                'has_items' => isset($fieldData['items']),
                'has_options' => isset($fieldData['options']),
                'has_values' => isset($fieldData['values']),
                'field_keys' => array_keys($fieldData)
            ]);
            
            // Проверяем, является ли поле типом списка
            // В Bitrix24 поля типа списка имеют type = 'enum', 'enumeration', 'list', 'crm_status', 'crm_category'
            if (in_array(strtolower($fieldType), ['enum', 'enumeration', 'list', 'crm_status', 'crm_category'])) {
                $fieldName = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
                $fieldValues = [];
                
                // Получаем значения списка
                // Пробуем разные форматы ответа от Bitrix24 API
                if (isset($fieldData['items']) && is_array($fieldData['items'])) {
                    // Детальное логирование структуры items
                    $this->logger->debug('Processing field items', [
                        'field_id' => $fieldId,
                        'items_count' => count($fieldData['items']),
                        'first_item_structure' => !empty($fieldData['items']) ? json_encode(array_slice($fieldData['items'], 0, 1, true), JSON_UNESCAPED_UNICODE) : 'empty',
                        'items_type' => gettype($fieldData['items'][0] ?? null)
                    ]);
                    
                    // Формат: items содержит массив значений
                    foreach ($fieldData['items'] as $item) {
                        if (is_array($item)) {
                            $itemId = $item['ID'] ?? $item['id'] ?? $item['VALUE'] ?? $item['value'] ?? null;
                            $itemName = $item['VALUE'] ?? $item['value'] ?? $item['NAME'] ?? $item['name'] ?? $item['TITLE'] ?? $item['title'] ?? '';
                        } else {
                            // Если items - это простой массив строк/чисел
                            $itemId = $item;
                            $itemName = (string)$item;
                        }
                        
                        if ($itemId !== null) {
                            $fieldValues[$itemId] = $itemName;
                        }
                    }
                } elseif (isset($fieldData['options']) && is_array($fieldData['options'])) {
                    // Альтернативный формат: options содержит массив значений
                    foreach ($fieldData['options'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                } elseif (isset($fieldData['values']) && is_array($fieldData['values'])) {
                    // Еще один вариант формата
                    foreach ($fieldData['values'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                }
                
                // Если значения не найдены в структуре поля, пробуем получить через отдельный API метод
                if (empty($fieldValues)) {
                    // Для enum полей используем crm.enum.fields
                    if (strtolower($fieldType) === 'enum') {
                        $enumMethod = 'crm.enum.fields';
                        $enumParams = [
                            'entityTypeId' => $entityTypeId,
                            'fieldId' => $fieldId
                        ];
                        
                        $enumResult = $this->makeApiCall($enumMethod, $enumParams);
                        
                        if ($enumResult && isset($enumResult['result']) && is_array($enumResult['result'])) {
                            foreach ($enumResult['result'] as $enumItem) {
                                if (is_array($enumItem)) {
                                    $itemId = $enumItem['ID'] ?? $enumItem['id'] ?? $enumItem['VALUE'] ?? null;
                                    $itemName = $enumItem['VALUE'] ?? $enumItem['value'] ?? $enumItem['NAME'] ?? $enumItem['name'] ?? '';
                                    
                                    if ($itemId !== null) {
                                        $fieldValues[$itemId] = $itemName;
                                    }
                                }
                            }
                        }
                    }
                }
                
                $this->logger->debug('Extracted field values', [
                    'field_id' => $fieldId,
                    'values_count' => count($fieldValues),
                    'values_sample' => array_slice($fieldValues, 0, 3, true)
                ]);
                
                $listFields[$fieldId] = [
                    'name' => $fieldName,
                    'type' => $fieldType,
                    'values' => $fieldValues
                ];
                
                $this->logger->debug('Found list field', [
                    'field_id' => $fieldId,
                    'field_name' => $fieldName,
                    'field_type' => $fieldType,
                    'values_count' => count($fieldValues)
                ]);
            }
        }

        $this->logger->info('Retrieved smart process list fields', [
            'entity_type_id' => $entityTypeId,
            'list_fields_count' => count($listFields),
            'field_ids' => array_keys($listFields)
        ]);

        return $listFields;
    }

    /**
     * Получение полей типа списка контакта
     * 
     * @return array|false Массив с полями типа списка и их значениями или false при ошибке
     * 
     * Формат возвращаемых данных:
     * [
     *   'field_id' => [
     *     'name' => 'Название поля',
     *     'type' => 'enum',
     *     'values' => [
     *       'ID' => 'Название значения',
     *       ...
     *     ]
     *   ],
     *   ...
     * ]
     */
    public function getContactListFields()
    {
        $this->logger->debug('Getting contact list fields');

        // Получаем все поля контакта (включая пользовательские поля)
        // Используем crm.contact.fields для получения всех полей контакта
        $method = 'crm.contact.fields';
        $params = [];

        $result = $this->makeApiCall($method, $params);

        if (!$result || !isset($result['result'])) {
            $this->logger->error('Failed to get contact fields', [
                'result' => $result
            ]);
            return false;
        }

        $fields = $result['result'];
        
        // Детальное логирование структуры ответа для отладки
        $this->logger->debug('Contact fields API response structure', [
            'method' => $method,
            'result_keys' => is_array($fields) ? array_keys($fields) : 'not_array',
            'fields_count' => is_array($fields) ? count($fields) : 0,
            'fields_type' => gettype($fields),
            'is_array' => is_array($fields),
            'sample_fields' => is_array($fields) ? array_slice(array_keys($fields), 0, 10) : 'not_array'
        ]);
        
        if (!is_array($fields)) {
            $this->logger->error('Contact fields data is not an array', [
                'fields_type' => gettype($fields)
            ]);
            return false;
        }
        
        $listFields = [];

        // Фильтруем поля типа списка (enum, list)
        foreach ($fields as $fieldId => $fieldData) {
            if (!is_array($fieldData)) {
                continue;
            }
            
            $fieldType = $fieldData['type'] ?? '';
            $fieldTitle = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
            
            // Детальное логирование для каждого поля
            $this->logger->debug('Processing contact field', [
                'field_id' => $fieldId,
                'field_title' => $fieldTitle,
                'field_type' => $fieldType,
                'has_items' => isset($fieldData['items']),
                'has_options' => isset($fieldData['options']),
                'has_values' => isset($fieldData['values']),
                'field_keys' => array_keys($fieldData)
            ]);
            
            // Проверяем, является ли поле типом списка
            // В Bitrix24 поля типа списка имеют type = 'enum', 'enumeration', 'list', 'crm_status', 'crm_category'
            if (in_array(strtolower($fieldType), ['enum', 'enumeration', 'list', 'crm_status', 'crm_category'])) {
                $fieldName = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
                $fieldValues = [];
                
                // Получаем значения списка
                // Пробуем разные форматы ответа от Bitrix24 API
                if (isset($fieldData['items']) && is_array($fieldData['items'])) {
                    // Детальное логирование структуры items
                    $this->logger->debug('Processing contact field items', [
                        'field_id' => $fieldId,
                        'items_count' => count($fieldData['items']),
                        'first_item_structure' => !empty($fieldData['items']) ? json_encode(array_slice($fieldData['items'], 0, 1, true), JSON_UNESCAPED_UNICODE) : 'empty',
                        'items_type' => gettype($fieldData['items'][0] ?? null)
                    ]);
                    
                    // Формат: items содержит массив значений
                    foreach ($fieldData['items'] as $item) {
                        if (is_array($item)) {
                            $itemId = $item['ID'] ?? $item['id'] ?? $item['VALUE'] ?? $item['value'] ?? null;
                            $itemName = $item['VALUE'] ?? $item['value'] ?? $item['NAME'] ?? $item['name'] ?? $item['TITLE'] ?? $item['title'] ?? '';
                        } else {
                            // Если items - это простой массив строк/чисел
                            $itemId = $item;
                            $itemName = (string)$item;
                        }
                        
                        if ($itemId !== null) {
                            $fieldValues[$itemId] = $itemName;
                        }
                    }
                } elseif (isset($fieldData['options']) && is_array($fieldData['options'])) {
                    // Альтернативный формат: options содержит массив значений
                    foreach ($fieldData['options'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                } elseif (isset($fieldData['values']) && is_array($fieldData['values'])) {
                    // Еще один вариант формата
                    foreach ($fieldData['values'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                }
                
                // Для контактов значения enum полей должны быть в ответе crm.contact.fields
                // Дополнительный вызов crm.enum.fields не требуется, так как он работает только для смарт-процессов
                
                $this->logger->debug('Extracted contact field values', [
                    'field_id' => $fieldId,
                    'values_count' => count($fieldValues),
                    'values_sample' => array_slice($fieldValues, 0, 3, true)
                ]);
                
                if (!empty($fieldValues)) {
                    $listFields[$fieldId] = [
                        'name' => $fieldName,
                        'type' => $fieldType,
                        'values' => $fieldValues
                    ];
                    
                    $this->logger->debug('Found contact list field', [
                        'field_id' => $fieldId,
                        'field_name' => $fieldName,
                        'field_type' => $fieldType,
                        'values_count' => count($fieldValues)
                    ]);
                }
            }
        }

        $this->logger->info('Retrieved contact list fields', [
            'list_fields_count' => count($listFields),
            'field_ids' => array_keys($listFields)
        ]);

        return $listFields;
    }

    /**
     * Получение полей типа списка компании
     * 
     * @return array|false Массив с полями типа списка и их значениями или false при ошибке
     * 
     * Формат возвращаемых данных:
     * [
     *   'field_id' => [
     *     'name' => 'Название поля',
     *     'type' => 'enum',
     *     'values' => [
     *       'ID' => 'Название значения',
     *       ...
     *     ]
     *   ],
     *   ...
     * ]
     */
    public function getCompanyListFields()
    {
        $this->logger->debug('Getting company list fields');

        // Получаем все поля компании (включая пользовательские поля)
        // Используем crm.company.fields для получения всех полей компании
        $method = 'crm.company.fields';
        $params = [];

        $result = $this->makeApiCall($method, $params);

        if (!$result || !isset($result['result'])) {
            $this->logger->error('Failed to get company fields', [
                'result' => $result
            ]);
            return false;
        }

        $fields = $result['result'];
        
        // Детальное логирование структуры ответа для отладки
        $this->logger->debug('Company fields API response structure', [
            'method' => $method,
            'result_keys' => is_array($fields) ? array_keys($fields) : 'not_array',
            'fields_count' => is_array($fields) ? count($fields) : 0,
            'fields_type' => gettype($fields),
            'is_array' => is_array($fields),
            'sample_fields' => is_array($fields) ? array_slice(array_keys($fields), 0, 10) : 'not_array'
        ]);
        
        if (!is_array($fields)) {
            $this->logger->error('Company fields data is not an array', [
                'fields_type' => gettype($fields)
            ]);
            return false;
        }
        
        $listFields = [];

        // Фильтруем поля типа списка (enum, list)
        foreach ($fields as $fieldId => $fieldData) {
            if (!is_array($fieldData)) {
                continue;
            }
            
            $fieldType = $fieldData['type'] ?? '';
            $fieldTitle = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
            
            // Детальное логирование для каждого поля
            $this->logger->debug('Processing company field', [
                'field_id' => $fieldId,
                'field_title' => $fieldTitle,
                'field_type' => $fieldType,
                'has_items' => isset($fieldData['items']),
                'has_options' => isset($fieldData['options']),
                'has_values' => isset($fieldData['values']),
                'field_keys' => array_keys($fieldData)
            ]);
            
            // Проверяем, является ли поле типом списка
            // В Bitrix24 поля типа списка имеют type = 'enum', 'enumeration', 'list', 'crm_status', 'crm_category'
            if (in_array(strtolower($fieldType), ['enum', 'enumeration', 'list', 'crm_status', 'crm_category'])) {
                $fieldName = $fieldData['title'] ?? $fieldData['name'] ?? $fieldId;
                $fieldValues = [];
                
                // Получаем значения списка
                // Пробуем разные форматы ответа от Bitrix24 API
                if (isset($fieldData['items']) && is_array($fieldData['items'])) {
                    // Детальное логирование структуры items
                    $this->logger->debug('Processing company field items', [
                        'field_id' => $fieldId,
                        'items_count' => count($fieldData['items']),
                        'first_item_structure' => !empty($fieldData['items']) ? json_encode(array_slice($fieldData['items'], 0, 1, true), JSON_UNESCAPED_UNICODE) : 'empty',
                        'items_type' => gettype($fieldData['items'][0] ?? null)
                    ]);
                    
                    // Формат: items содержит массив значений
                    foreach ($fieldData['items'] as $item) {
                        if (is_array($item)) {
                            $itemId = $item['ID'] ?? $item['id'] ?? $item['VALUE'] ?? $item['value'] ?? null;
                            $itemName = $item['VALUE'] ?? $item['value'] ?? $item['NAME'] ?? $item['name'] ?? $item['TITLE'] ?? $item['title'] ?? '';
                        } else {
                            // Если items - это простой массив строк/чисел
                            $itemId = $item;
                            $itemName = (string)$item;
                        }
                        
                        if ($itemId !== null) {
                            $fieldValues[$itemId] = $itemName;
                        }
                    }
                } elseif (isset($fieldData['options']) && is_array($fieldData['options'])) {
                    // Альтернативный формат: options содержит массив значений
                    foreach ($fieldData['options'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                } elseif (isset($fieldData['values']) && is_array($fieldData['values'])) {
                    // Еще один вариант формата
                    foreach ($fieldData['values'] as $itemId => $itemName) {
                        if (is_array($itemName)) {
                            $itemName = $itemName['VALUE'] ?? $itemName['value'] ?? $itemName['NAME'] ?? $itemName['name'] ?? (string)$itemId;
                        }
                        $fieldValues[$itemId] = $itemName;
                    }
                }
                
                // Для компаний значения enum полей должны быть в ответе crm.company.fields
                // Дополнительный вызов crm.enum.fields не требуется, так как он работает только для смарт-процессов
                
                $this->logger->debug('Extracted company field values', [
                    'field_id' => $fieldId,
                    'values_count' => count($fieldValues),
                    'values_sample' => array_slice($fieldValues, 0, 3, true)
                ]);
                
                if (!empty($fieldValues)) {
                    $listFields[$fieldId] = [
                        'name' => $fieldName,
                        'type' => $fieldType,
                        'values' => $fieldValues
                    ];
                    
                    $this->logger->debug('Found company list field', [
                        'field_id' => $fieldId,
                        'field_name' => $fieldName,
                        'field_type' => $fieldType,
                        'values_count' => count($fieldValues)
                    ]);
                }
            }
        }

        $this->logger->info('Retrieved company list fields', [
            'list_fields_count' => count($listFields),
            'field_ids' => array_keys($listFields)
        ]);

        return $listFields;
    }
}

