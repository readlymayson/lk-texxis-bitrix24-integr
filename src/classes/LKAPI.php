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
        $this->logger->info('Creating personal account locally', [
            'contact_id' => $contactData['ID'] ?? null,
            'company_id' => $companyData['ID'] ?? null
        ]);

        // Локальная обработка - данные уже сохранены в LocalStorage
        // Просто логируем успешное создание
        $this->logger->info('Personal account created successfully', [
            'contact_id' => $contactData['ID'] ?? null,
            'contact_name' => ($contactData['NAME'] ?? '') . ' ' . ($contactData['LAST_NAME'] ?? '')
        ]);

        return true;
    }

    /**
     * Обновление данных личного кабинета
     */
    public function updateLK($lkId, $contactData, $companyData = null)
    {
        $this->logger->info('Updating personal account locally', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID'] ?? null,
            'company_id' => $companyData['ID'] ?? null
        ]);

        // Локальная обработка - данные уже обновлены в LocalStorage
        $this->logger->info('Personal account updated successfully', [
            'lk_id' => $lkId,
            'contact_name' => ($contactData['NAME'] ?? '') . ' ' . ($contactData['LAST_NAME'] ?? '')
        ]);

        return true;
    }

    /**
     * Удаление личного кабинета
     */
    public function deleteLK($lkId)
    {
        $this->logger->info('Deleting personal account locally', ['lk_id' => $lkId]);

        // Локальная обработка - данные уже удалены из LocalStorage
        $this->logger->info('Personal account deleted successfully', ['lk_id' => $lkId]);

        return true;
    }

    /**
     * Синхронизация данных контакта в ЛК
     */
    public function syncContactByBitrixId($bitrixId, $contactData)
    {
        $this->logger->info('Syncing contact data locally by bitrix_id', [
            'contact_bitrix_id' => $bitrixId,
            'contact_name' => ($contactData['NAME'] ?? '') . ' ' . ($contactData['LAST_NAME'] ?? '')
        ]);

        // Локальная обработка - данные уже сохранены в LocalStorage
        $this->logger->info('Contact data synced successfully', [
            'contact_bitrix_id' => $bitrixId,
            'contact_name' => ($contactData['NAME'] ?? '') . ' ' . ($contactData['LAST_NAME'] ?? '')
        ]);

        return true;
    }

    /**
     * Синхронизация данных компании в ЛК
     */
    public function syncCompanyByBitrixId($bitrixId, $companyData)
    {
        $this->logger->info('Syncing company data locally by bitrix_id', [
            'company_bitrix_id' => $bitrixId,
            'company_title' => $companyData['TITLE'] ?? 'Unknown',
            'contact_id' => $companyData['CONTACT_ID'] ?? null
        ]);

        // Локальная обработка - данные уже сохранены в LocalStorage
        $this->logger->info('Company data synced successfully', [
            'company_bitrix_id' => $bitrixId,
            'company_title' => $companyData['TITLE'] ?? 'Unknown',
            'linked_to_contact' => $companyData['CONTACT_ID'] ?? null
        ]);

        return true;
    }

    /**
     * Синхронизация данных проекта в ЛК
     */
    public function syncProjectByClient($clientId, $projectData)
    {
        $this->logger->info('Syncing project data locally by client (bitrix_id)', [
            'client_bitrix_id' => $clientId,
            'project_id' => $projectData['bitrix_id'] ?? null,
            'project_title' => $projectData['organization_name'] ?? $projectData['TITLE'] ?? 'Unknown'
        ]);

        // Локальная обработка - данные уже сохранены в LocalStorage
        $this->logger->info('Project data synced successfully', [
            'client_bitrix_id' => $clientId,
            'project_id' => $projectData['bitrix_id'] ?? null,
            'project_title' => $projectData['organization_name'] ?? $projectData['TITLE'] ?? 'Unknown'
        ]);

        return true;
    }

    /**
     * Синхронизация данных менеджера в ЛК
     */
    public function syncManager($managerData)
    {
        $this->logger->info('Syncing manager data locally', [
            'manager_id' => $managerData['ID'] ?? null,
            'manager_name' => ($managerData['NAME'] ?? '') . ' ' . ($managerData['LAST_NAME'] ?? '')
        ]);

        // Локальная обработка - данные уже сохранены в LocalStorage
        $this->logger->info('Manager data synced successfully', [
            'manager_id' => $managerData['ID'] ?? null,
            'manager_name' => ($managerData['NAME'] ?? '') . ' ' . ($managerData['LAST_NAME'] ?? '')
        ]);

        return true;
    }

    /**
     * Получение данных менеджера
     */
    public function getManager($managerId)
    {
        $this->logger->info('Getting manager data locally', ['manager_id' => $managerId]);

        // Для локальной работы возвращаем true (данные уже доступны в LocalStorage)
        $this->logger->info('Manager data retrieved successfully', ['manager_id' => $managerId]);

        return ['success' => true, 'manager_id' => $managerId];
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
        $result = [
            'bitrix_id' => $contactData['ID'] ?? null,
            'name' => $contactData[$mapping['name']] ?? '',
            'last_name' => $contactData[$mapping['last_name']] ?? '',
            'phone' => $this->extractPhone($contactData[$mapping['phone']] ?? []),
            'has_lk' => !empty($contactData[$mapping['lk_client_field']]),
            'lk_created' => $contactData[$mapping['lk_client_field']] ?? null,
        ];

        // Email теперь опциональный - добавляем только если он есть
        $email = $this->extractEmail($contactData[$mapping['email']] ?? []);
        if (!empty($email)) {
            $result['email'] = $email;
        } else {
            $this->logger->debug('Contact email not found or empty, creating LK without email', [
                'contact_id' => $contactData['ID'],
                'email_field_data' => $contactData[$mapping['email']] ?? null
            ]);
        }

        return $result;
    }

    /**
     * Маппинг полей компании
     */
    private function mapCompanyFields($companyData)
    {
        $mapping = $this->config['field_mapping']['company'];
        $result = [
            'bitrix_id' => $companyData['ID'] ?? null,
            'title' => $companyData[$mapping['title']] ?? '',
            'phone' => $this->extractPhone($companyData[$mapping['phone']] ?? []),
            'has_lk' => false, // Компании не имеют поля личного кабинета
        ];

        $email = $this->extractEmail($companyData[$mapping['email']] ?? []);
        if (!empty($email)) {
            $result['email'] = $email;
        } else {
            $this->logger->debug('Company email not found or empty, processing without email', [
                'company_id' => $companyData['ID'],
                'email_field_data' => $companyData[$mapping['email']] ?? null
            ]);
        }

        return $result;
    }

    /**
     * Маппинг полей проекта
     */
    protected function mapProjectFields($projectData)
    {
        $mapping = $this->config['field_mapping']['smart_process'];

        // Отладка маппинга
        $this->logger->debug('Mapping project fields', [
            'project_data_keys' => array_keys($projectData),
            'id_value' => $projectData['id'] ?? $projectData['ID'] ?? 'NOT_FOUND',
            'title_value' => $projectData['title'] ?? $projectData['TITLE'] ?? 'NOT_FOUND',
            'organization_field' => $mapping['organization_name'],
            'organization_value' => $projectData[$mapping['organization_name']] ?? 'NOT_FOUND',
            'object_field' => $mapping['object_name'],
            'object_value' => $projectData[$mapping['object_name']] ?? 'NOT_FOUND',
            'contact_field' => $mapping['client_id'],
            'contact_value' => $projectData[$mapping['client_id']] ?? 'NOT_FOUND',
            'all_uf_fields' => array_filter(array_keys($projectData), fn($k) => str_starts_with($k, 'ufCrm')),
            'raw_contact_id' => $projectData['contactId'] ?? $projectData['contact_id'] ?? 'NOT_FOUND'
        ]);

        return [
            'bitrix_id' => $projectData['id'] ?? $projectData['ID'] ?? null,
            'organization_name' => $projectData[$mapping['organization_name']] ?? '',
            'object_name' => $projectData[$mapping['object_name']] ?? '',
            'system_type' => $projectData[$mapping['system_type']] ?? '',
            'location' => $projectData[$mapping['location']] ?? '',
            'implementation_date' => $projectData[$mapping['implementation_date']] ?? null,
            'status' => $projectData[$mapping['status']] ?? '',
            'client_id' => $projectData[$mapping['client_id']] ?? null, // Связь с клиентом
            'manager_id' => $projectData['ASSIGNED_BY_ID'] ?? null, // Ответственный менеджер
            'created_at' => $projectData['created_at'] ?? $projectData['DATE_CREATE'] ?? null,
            'updated_at' => $projectData['updated_at'] ?? $projectData['DATE_MODIFY'] ?? null,
        ];
    }

    /**
     * Маппинг полей менеджера
     */
    private function mapManagerFields($managerData)
    {
        $mapping = $this->config['field_mapping']['user'];
        return [
            'bitrix_id' => $managerData['ID'] ?? null,
            'name' => $managerData[$mapping['name']] ?? '',
            'last_name' => $managerData[$mapping['last_name']] ?? '',
            'email' => $this->extractEmail($managerData[$mapping['email']] ?? []),
            'phone' => $this->extractPhone($managerData[$mapping['phone']] ?? []),
            'position' => $managerData[$mapping['position']] ?? '',
            'photo' => $managerData[$mapping['photo']] ?? '',
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

