<?php
# -*- coding: utf-8 -*-

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
            // Проверка наличия необходимых заголовков
            if (!isset($headers['User-Agent']) || !str_contains($headers['User-Agent'], 'Bitrix24')) {
                $this->logger->warning('Invalid User-Agent in webhook request', ['headers' => $headers]);
                return false;
            }

            // Проверка типа контента
            if (!isset($headers['Content-Type']) || !str_contains($headers['Content-Type'], 'application/json')) {
                $this->logger->warning('Invalid Content-Type in webhook request', ['content_type' => $headers['Content-Type'] ?? 'not set']);
                return false;
            }

            // Валидация JSON тела запроса
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON in webhook body', ['error' => json_last_error_msg(), 'body' => $body]);
                return false;
            }

            $this->logger->debug('Webhook request validated successfully');
            return $data;

        } catch (Exception $e) {
            $this->logger->error('Error validating webhook request', [
                'error' => $e->getMessage(),
                'headers' => $headers
            ]);
            return false;
        }
    }

    /**
     * Получение данных сущности по ID через API
     */
    public function getEntityData($entityType, $entityId)
    {
        $method = $this->getApiMethodForEntity($entityType, 'get');

        if (!$method) {
            $this->logger->error('Unsupported entity type for API call', ['entity_type' => $entityType]);
            return false;
        }

        $params = ['id' => $entityId];

        return $this->makeApiCall($method, $params);
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
        if (!empty($select)) {
            $params['select'] = $select;
        }

        return $this->makeApiCall($method, $params);
    }

    /**
     * Обновление данных сущности
     */
    public function updateEntity($entityType, $entityId, $fields)
    {
        $method = $this->getApiMethodForEntity($entityType, 'update');

        if (!$method) {
            $this->logger->error('Unsupported entity type for update API call', ['entity_type' => $entityType]);
            return false;
        }

        $params = [
            'id' => $entityId,
            'fields' => $fields
        ];

        return $this->makeApiCall($method, $params);
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
            CURLOPT_TIMEOUT => $this->config['lk']['timeout'],
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
            ]
        ];

        return $methods[$entityType][$action] ?? false;
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
            'ONCRM_DYNAMIC_ITEM' => 'smart_process'
        ];

        foreach ($mapping as $prefix => $type) {
            if (str_starts_with($eventName, $prefix)) {
                return $type;
            }
        }

        return false;
    }
}

