<?php
# -*- coding: utf-8 -*-

/**
 * Класс для работы с API личного кабинета
 */
class LKAPI
{
    private $config;
    private $logger;

    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Создание личного кабинета
     */
    public function createLK($contactData, $companyData = null)
    {
        $this->logger->info('Creating personal account', [
            'contact_id' => $contactData['ID'] ?? null,
            'company_id' => $companyData['ID'] ?? null
        ]);

        $payload = $this->prepareLKData($contactData, $companyData);

        return $this->makeLKApiCall('lk/create', $payload);
    }

    /**
     * Обновление данных личного кабинета
     */
    public function updateLK($lkId, $contactData, $companyData = null)
    {
        $this->logger->info('Updating personal account', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID'] ?? null,
            'company_id' => $companyData['ID'] ?? null
        ]);

        $payload = $this->prepareLKData($contactData, $companyData);
        $payload['lk_id'] = $lkId;

        return $this->makeLKApiCall('lk/update', $payload);
    }

    /**
     * Удаление личного кабинета
     */
    public function deleteLK($lkId)
    {
        $this->logger->info('Deleting personal account', ['lk_id' => $lkId]);

        return $this->makeLKApiCall('lk/delete', ['lk_id' => $lkId]);
    }

    /**
     * Синхронизация данных контакта в ЛК
     */
    public function syncContact($lkId, $contactData)
    {
        $this->logger->info('Syncing contact data to LK', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID'] ?? null
        ]);

        $payload = [
            'lk_id' => $lkId,
            'contact' => $this->mapContactFields($contactData)
        ];

        return $this->makeLKApiCall('lk/sync/contact', $payload);
    }

    /**
     * Синхронизация данных компании в ЛК
     */
    public function syncCompany($lkId, $companyData)
    {
        $this->logger->info('Syncing company data to LK', [
            'lk_id' => $lkId,
            'company_id' => $companyData['ID'] ?? null
        ]);

        $payload = [
            'lk_id' => $lkId,
            'company' => $this->mapCompanyFields($companyData)
        ];

        return $this->makeLKApiCall('lk/sync/company', $payload);
    }

    /**
     * Синхронизация данных проекта в ЛК
     */
    public function syncProject($lkId, $projectData)
    {
        $this->logger->info('Syncing project data to LK', [
            'lk_id' => $lkId,
            'project_id' => $projectData['ID'] ?? null
        ]);

        $payload = [
            'lk_id' => $lkId,
            'project' => $this->mapProjectFields($projectData)
        ];

        return $this->makeLKApiCall('lk/sync/project', $payload);
    }

    /**
     * Выполнение запроса к API личного кабинета
     */
    private function makeLKApiCall($endpoint, $data)
    {
        $url = rtrim($this->config['lk']['api_url'], '/') . '/' . $endpoint;

        $data['api_key'] = $this->config['lk']['api_key'];
        $postData = json_encode($data, JSON_UNESCAPED_UNICODE);

        $this->logger->debug('Making LK API call', [
            'endpoint' => $endpoint,
            'url' => $url,
            'data_keys' => array_keys($data)
        ]);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
                'X-API-Key: ' . $this->config['lk']['api_key']
            ],
            CURLOPT_TIMEOUT => $this->config['lk']['timeout'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            $this->logger->error('LK API CURL error', [
                'endpoint' => $endpoint,
                'error' => $error
            ]);
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('LK API returned error status code', [
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return false;
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Invalid JSON response from LK API', [
                'endpoint' => $endpoint,
                'response' => $response,
                'json_error' => json_last_error_msg()
            ]);
            return false;
        }

        $this->logger->debug('LK API call successful', [
            'endpoint' => $endpoint,
            'success' => $result['success'] ?? null
        ]);

        return $result;
    }

    /**
     * Подготовка данных для создания/обновления ЛК
     */
    private function prepareLKData($contactData, $companyData = null)
    {
        $data = [
            'contact' => $this->mapContactFields($contactData),
            'created_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_integration'
        ];

        if ($companyData) {
            $data['company'] = $this->mapCompanyFields($companyData);
        }

        return $data;
    }

    /**
     * Маппинг полей контакта
     */
    private function mapContactFields($contactData)
    {
        $mapping = $this->config['field_mapping']['contact'];

        return [
            'bitrix_id' => $contactData['ID'] ?? null,
            'name' => $contactData[$mapping['name']] ?? '',
            'last_name' => $contactData[$mapping['last_name']] ?? '',
            'email' => $this->extractEmail($contactData[$mapping['email']] ?? []),
            'phone' => $this->extractPhone($contactData[$mapping['phone']] ?? []),
            'has_lk' => !empty($contactData[$mapping['lk_client_field']]),
            'lk_created' => $contactData[$mapping['lk_client_field']] ?? null,
        ];
    }

    /**
     * Маппинг полей компании
     */
    private function mapCompanyFields($companyData)
    {
        $mapping = $this->config['field_mapping']['company'];

        return [
            'bitrix_id' => $companyData['ID'] ?? null,
            'title' => $companyData[$mapping['title']] ?? '',
            'email' => $this->extractEmail($companyData[$mapping['email']] ?? []),
            'phone' => $this->extractPhone($companyData[$mapping['phone']] ?? []),
            'has_lk' => !empty($companyData[$mapping['lk_client_field']]),
        ];
    }

    /**
     * Маппинг полей проекта
     */
    private function mapProjectFields($projectData)
    {
        return [
            'bitrix_id' => $projectData['ID'] ?? null,
            'title' => $projectData['title'] ?? $projectData['TITLE'] ?? '',
            'description' => $projectData['description'] ?? $projectData['DESCRIPTION'] ?? '',
            'status' => $projectData['stage_id'] ?? $projectData['STAGE_ID'] ?? '',
            'created_at' => $projectData['created_at'] ?? $projectData['DATE_CREATE'] ?? null,
            'updated_at' => $projectData['updated_at'] ?? $projectData['DATE_MODIFY'] ?? null,
        ];
    }

    /**
     * Извлечение email из массива Битрикс24
     */
    private function extractEmail($emails)
    {
        if (is_array($emails) && !empty($emails)) {
            foreach ($emails as $email) {
                if (isset($email['VALUE']) && filter_var($email['VALUE'], FILTER_VALIDATE_EMAIL)) {
                    return $email['VALUE'];
                }
            }
        }
        return '';
    }

    /**
     * Извлечение телефона из массива Битрикс24
     */
    private function extractPhone($phones)
    {
        if (is_array($phones) && !empty($phones)) {
            foreach ($phones as $phone) {
                if (isset($phone['VALUE'])) {
                    return $phone['VALUE'];
                }
            }
        }
        return '';
    }
}

