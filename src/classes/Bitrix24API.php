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
            if (empty($userAgent) ||
                (!str_contains($userAgent, 'Bitrix24') && !str_contains($userAgent, 'Bitrix24 Webhook Engine'))) {
                $this->logger->warning('Invalid User-Agent in webhook request', [
                    'user_agent' => $userAgent,
                    'headers' => $headers
                ]);
                return false;
            }

            $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? '';

            if (str_contains($contentType, 'application/json')) {
                $data = json_decode($body, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $this->logger->error('Invalid JSON in webhook body', [
                        'error' => json_last_error_msg(),
                        'body' => $body,
                        'content_type' => $contentType
                    ]);
                    return false;
                }
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($body, $data);
                if (empty($data)) {
                    $this->logger->error('Failed to parse URL-encoded webhook body', [
                        'body' => $body,
                        'content_type' => $contentType
                    ]);
                    return false;
                }
            } else {
                $this->logger->warning('Unsupported Content-Type in webhook request', [
                    'content_type' => $contentType,
                    'supported_types' => ['application/json', 'application/x-www-form-urlencoded'],
                    'headers' => $headers
                ]);
                return false;
            }

            $expectedToken = $this->config['bitrix24']['application_token'];
            $receivedToken = $data['auth']['application_token'] ?? '';

            if (!empty($expectedToken) && $receivedToken !== $expectedToken) {
                $this->logger->error('Invalid application token in webhook request', [
                    'expected_token' => substr($expectedToken, 0, 8) . '...', // Логируем только начало токена
                    'received_token' => substr($receivedToken, 0, 8) . '...',
                    'auth_data' => $data['auth'] ?? []
                ]);
                return false;
            }

            $this->logger->debug('Webhook request validated successfully', [
                'content_type' => $contentType,
                'format' => str_contains($contentType, 'json') ? 'json' : 'url-encoded',
                'data_keys' => array_keys($data),
                'application_token_valid' => !empty($expectedToken) ? 'checked' : 'not_configured'
            ]);
            return $data;

        } catch (Exception $e) {
            $this->logger->error('Error validating webhook request', [
                'error' => $e->getMessage(),
                'headers' => $headers,
                'body_length' => strlen($body)
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
        $url = $this->config['bitrix24']['webhook_url'] . $method . '.json';

        $postData = json_encode($params, JSON_UNESCAPED_UNICODE);

        $this->logger->debug('Making API call', [
            'method' => $method,
            'url' => $url,
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
                'url' => $url
            ]);
            return false;
        }

        if ($httpCode !== 200) {
            $this->logger->error('API call returned non-200 status code', [
                'method' => $method,
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from API', [
                'method' => $method,
                'response' => $response,
                'json_error' => json_last_error_msg()
            ]);
            return false;
        }

        if (isset($result['error'])) {
            $this->logger->error('API returned error', [
                'method' => $method,
                'error' => $result['error'],
                'error_description' => $result['error_description'] ?? ''
            ]);
            return false;
        }

        $this->logger->debug('API call successful', [
            'method' => $method,
            'result_count' => isset($result['result']) ? count($result['result']) : 0
        ]);

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
            'deal' => [
                'get' => 'crm.deal.get',
                'list' => 'crm.deal.list',
                'update' => 'crm.deal.update',
                'add' => 'crm.deal.add',
                'delete' => 'crm.deal.delete'
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
        foreach ($mapping as $key => $value) {
            if ($key === 'lk_client_values' || $key === 'lk_client_field') {
                continue;
            }

            if (is_string($value) && !empty($value)) {
                $fields[] = $value;
            }
        }

        if (!in_array('ID', $fields)) {
            $fields[] = 'ID';
        }

        if (($entityType === 'company' || $entityType === 'deal') && !in_array('CONTACT_ID', $fields)) {
            if (isset($mapping['contact_id']) && !empty($mapping['contact_id'])) {
                $fields[] = $mapping['contact_id'];
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
            'ONCRMDEAL' => 'deal',
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
     * Создание карточки в смарт-процессе "Изменение данных в ЛК"
     * 
     * @param array $data Данные для создания карточки
     * @return array|false Результат создания или false при ошибке
     */
    public function createChangeDataCard($data)
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
        
        if (isset($data['contact_id']) && !empty($mapping['contact_id'])) {
            $fields[$mapping['contact_id']] = $data['contact_id'];
        }
        
        if (isset($data['company_id']) && !empty($mapping['company_id'])) {
            $fields[$mapping['company_id']] = $data['company_id'];
        }
        
        if (isset($data['manager_id']) && !empty($mapping['manager_id'])) {
            $fields[$mapping['manager_id']] = $data['manager_id'];
        }

        if (isset($data['new_email']) && !empty($mapping['new_email'])) {
            $fields[$mapping['new_email']] = $data['new_email'];
        }
        
        if (isset($data['new_phone']) && !empty($mapping['new_phone'])) {
            $fields[$mapping['new_phone']] = $data['new_phone'];
        }
        
        if (isset($data['change_reason_personal']) && !empty($mapping['change_reason_personal'])) {
            $fields[$mapping['change_reason_personal']] = $data['change_reason_personal'];
        }

        if (isset($data['new_company_name']) && !empty($mapping['new_company_name'])) {
            $fields[$mapping['new_company_name']] = $data['new_company_name'];
        }
        
        if (isset($data['new_company_website']) && !empty($mapping['new_company_website'])) {
            $fields[$mapping['new_company_website']] = $data['new_company_website'];
        }
        
        if (isset($data['new_company_inn']) && !empty($mapping['new_company_inn'])) {
            $fields[$mapping['new_company_inn']] = $data['new_company_inn'];
        }
        
        if (isset($data['new_company_phone']) && !empty($mapping['new_company_phone'])) {
            $fields[$mapping['new_company_phone']] = $data['new_company_phone'];
        }
        
        if (isset($data['change_reason_company']) && !empty($mapping['change_reason_company'])) {
            $fields[$mapping['change_reason_company']] = $data['change_reason_company'];
        }

        $this->logger->debug('Prepared fields for change data card', [
            'smart_process_id' => $smartProcessId,
            'fields' => $fields
        ]);

        return $this->addSmartProcessItem($smartProcessId, $fields);
    }

    /**
     * Создание карточки в смарт-процессе "Удаление пользовательских данных"
     * 
     * @param array $data Данные для создания карточки
     * @return array|false Результат создания или false при ошибке
     */
    public function createDeleteDataCard($data)
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
        
        if (isset($data['contact_id']) && !empty($mapping['contact_id'])) {
            $fields[$mapping['contact_id']] = $data['contact_id'];
        }
        
        if (isset($data['company_id']) && !empty($mapping['company_id'])) {
            $fields[$mapping['company_id']] = $data['company_id'];
        }
        
        if (isset($data['manager_id']) && !empty($mapping['manager_id'])) {
            $fields[$mapping['manager_id']] = $data['manager_id'];
        }

        $this->logger->debug('Prepared fields for delete data card', [
            'smart_process_id' => $smartProcessId,
            'fields' => $fields
        ]);

        return $this->addSmartProcessItem($smartProcessId, $fields);
    }
}

