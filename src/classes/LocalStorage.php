<?php
# -*- coding: utf-8 -*-

/**
 * Класс для локального хранения данных интеграции с Битрикс24
 * Заменяет API личного кабинета локальным хранением
 */
class LocalStorage
{
    private $logger;
    private $dataDir;
    private $contactsFile;
    private $companiesFile;
    private $dealsFile;
    private $projectsFile; // ДОБАВИТЬ ДЛЯ ПРОЕКТОВ
    private $managersFile; // ДОБАВИТЬ ДЛЯ МЕНЕДЖЕРОВ

    public function __construct($logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../data';
        $this->contactsFile = $this->dataDir . '/contacts.json';
        $this->companiesFile = $this->dataDir . '/companies.json';
        $this->dealsFile = $this->dataDir . '/deals.json';
        $this->projectsFile = $this->dataDir . '/projects.json'; // ДОБАВИТЬ
        $this->managersFile = $this->dataDir . '/managers.json'; // ДОБАВИТЬ

        $this->ensureDataDirectory();
    }

    /**
     * Создание директории для данных
     */
    private function ensureDataDirectory()
    {
        if (!is_dir($this->dataDir)) {
            mkdir($this->dataDir, 0755, true);
        }
    }

    /**
     * Чтение данных из файла
     */
    private function readData($file)
    {
        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        return $data ?: [];
    }

    /**
     * Запись данных в файл
     */
    private function writeData($file, $data)
    {
        $this->logger->info('Writing data to file', [
            'file' => basename($file),
            'data_keys_count' => count($data),
            'file_writable' => is_writable($file),
            'file_exists' => file_exists($file)
        ]);

        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            $this->logger->error('JSON encoding failed', [
                'file' => basename($file),
                'json_error' => json_last_error_msg(),
                'data_sample' => substr(print_r($data, true), 0, 200)
            ]);
            return false;
        }

        $this->logger->info('JSON encoding successful', [
            'file' => basename($file),
            'json_length' => strlen($jsonData)
        ]);

        $writeResult = file_put_contents($file, $jsonData);

        if ($writeResult === false) {
            $this->logger->error('File write failed', [
                'file' => basename($file),
                'file_writable' => is_writable($file),
                'disk_free_space' => disk_free_space(dirname($file)) ?? 'unknown'
            ]);
            return false;
        }

        $this->logger->info('File write successful', [
            'file' => basename($file),
            'bytes_written' => $writeResult,
            'final_file_size' => filesize($file) ?? 'unknown'
        ]);

        return $writeResult;
    }

    /**
     * Создание личного кабинета для контакта
     */
    public function createLK($contactData)
    {
        $this->logger->info('Creating local LK for contact', ['contact_id' => $contactData['ID']]);

        $contacts = $this->readData($this->contactsFile);

        // Генерируем ID ЛК
        $lkId = 'LK-' . time() . '-' . $contactData['ID'];

        $lkData = [
            'id' => $lkId,
            'bitrix_id' => $contactData['ID'],
            'name' => $contactData['NAME'] ?? '',
            'last_name' => $contactData['LAST_NAME'] ?? '',
            'email' => $contactData['EMAIL'] ?? '',
            'phone' => $contactData['PHONE'] ?? '',
            'company' => $contactData['COMPANY_ID'] ?? null,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $contacts[$contactData['ID']] = $lkData;
        $this->writeData($this->contactsFile, $contacts);

        $this->logger->info('Local LK created successfully', ['lk_id' => $lkId, 'contact_id' => $contactData['ID']]);

        return true;
    }

    /**
     * Синхронизация данных контакта
     */
    public function syncContactByBitrixId($bitrixId, $contactData)
    {
        $this->logger->info('Syncing contact data locally by bitrix_id - START', [
            'contact_bitrix_id' => $bitrixId,
            'contact_name' => $contactData['NAME'] ?? 'N/A',
            'contact_last_name' => $contactData['LAST_NAME'] ?? 'N/A'
        ]);

        $contacts = $this->readData($this->contactsFile);
        $this->logger->info('Contacts file read', [
            'contacts_count' => count($contacts),
            'file_exists' => file_exists($this->contactsFile),
            'file_readable' => is_readable($this->contactsFile),
            'file_writable' => is_writable($this->contactsFile)
        ]);

        if (!isset($contacts[$contactData['ID']])) {
            $this->logger->warning('Contact not found in local storage, creating new', [
                'contact_id' => $contactData['ID'],
                'available_contacts' => array_keys($contacts)
            ]);
            return $this->createLK($contactData);
        }

        $oldData = $contacts[$contactData['ID']];
        $this->logger->info('Contact found, preparing update', [
            'contact_id' => $contactData['ID'],
            'old_name' => $oldData['name'] ?? 'N/A',
            'old_updated_at' => $oldData['updated_at'] ?? 'N/A'
        ]);

        // Обновляем данные контакта
        $contacts[$contactData['ID']]['name'] = $contactData['NAME'] ?? $contacts[$contactData['ID']]['name'];
        $contacts[$contactData['ID']]['last_name'] = $contactData['LAST_NAME'] ?? $contacts[$contactData['ID']]['last_name'];
        $contacts[$contactData['ID']]['email'] = $contactData['EMAIL'] ?? $contacts[$contactData['ID']]['email'];
        $contacts[$contactData['ID']]['phone'] = $contactData['PHONE'] ?? $contacts[$contactData['ID']]['phone'];
        $contacts[$contactData['ID']]['company'] = $contactData['COMPANY_ID'] ?? $contacts[$contactData['ID']]['company'];
        $newUpdatedAt = date('Y-m-d H:i:s');
        $contacts[$contactData['ID']]['updated_at'] = $newUpdatedAt;

        $this->logger->info('Contact data updated in memory', [
            'contact_id' => $contactData['ID'],
            'new_name' => $contacts[$contactData['ID']]['name'],
            'new_last_name' => $contacts[$contactData['ID']]['last_name'],
            'new_updated_at' => $newUpdatedAt,
            'email_is_array' => is_array($contacts[$contactData['ID']]['email']),
            'phone_is_array' => is_array($contacts[$contactData['ID']]['phone'])
        ]);

        $writeResult = $this->writeData($this->contactsFile, $contacts);
        $this->logger->info('Contact data write attempt completed', [
            'contact_id' => $contactData['ID'],
            'write_result' => $writeResult !== false,
            'file_size_after_write' => filesize($this->contactsFile) ?? 'unknown'
        ]);

        // Проверяем, что данные действительно сохранились
        $verifyContacts = $this->readData($this->contactsFile);
        $verifyData = $verifyContacts[$contactData['ID']] ?? null;
        $this->logger->info('Contact data verification after write', [
            'contact_id' => $contactData['ID'],
            'verification_success' => $verifyData !== null,
            'verified_name' => $verifyData['name'] ?? 'N/A',
            'verified_updated_at' => $verifyData['updated_at'] ?? 'N/A'
        ]);

        $this->logger->info('Contact data synced successfully - END', ['contact_id' => $contactData['ID']]);

        return true;
    }

    /**
     * Синхронизация данных компании
     */
    public function syncCompanyByBitrixId($bitrixId, $companyData)
    {
        $this->logger->info('Syncing company data locally by bitrix_id', ['company_bitrix_id' => $bitrixId, 'company_title' => $companyData['TITLE'] ?? 'N/A']);

        $companies = $this->readData($this->companiesFile);

        $companies[$companyData['ID']] = [
            'id' => $companyData['ID'],
            'title' => $companyData['TITLE'] ?? '',
            'email' => $companyData['EMAIL'] ?? '',
            'phone' => $companyData['PHONE'] ?? '',
            'industry' => $companyData['INDUSTRY'] ?? '',
            'employees' => $companyData['EMPLOYEES'] ?? '',
            'revenue' => $companyData['REVENUE'] ?? '',
            'address' => $companyData['ADDRESS'] ?? '',
            'created_at' => $companyData['DATE_CREATE'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->companiesFile, $companies);

        $this->logger->info('Company data synced successfully', ['company_id' => $companyData['ID']]);

        return true;
    }

    /**
     * Получение всех контактов
     */
    public function getAllContacts()
    {
        return $this->readData($this->contactsFile);
    }

    /**
     * Получение последнего обновленного контакта
     */
    public function getLastUpdatedContact()
    {
        $contacts = $this->readData($this->contactsFile);

        if (empty($contacts)) {
            return null;
        }

        // Сортируем по времени обновления (новые сначала)
        uasort($contacts, function($a, $b) {
            return strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']);
        });

        // Возвращаем первый (самый свежий)
        return reset($contacts);
    }

    /**
     * Получение контактов отсортированных по времени обновления
     */
    public function getContactsSortedByUpdate($limit = null)
    {
        $contacts = $this->readData($this->contactsFile);

        if (empty($contacts)) {
            return [];
        }

        // Сортируем по времени обновления (новые сначала)
        uasort($contacts, function($a, $b) {
            return strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']);
        });

        if ($limit !== null) {
            return array_slice($contacts, 0, $limit, true);
        }

        return $contacts;
    }

    /**
     * Получение контакта по ID
     */
    public function getContact($contactId)
    {
        $contacts = $this->readData($this->contactsFile);
        return $contacts[$contactId] ?? null;
    }

    /**
     * Получение контакта по email
     */
    public function getContactByEmail($email)
    {
        $contacts = $this->readData($this->contactsFile);

        foreach ($contacts as $contact) {
            if (isset($contact['email']) && $contact['email'] === $email) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Получение всех компаний
     */
    public function getAllCompanies()
    {
        return $this->readData($this->companiesFile);
    }

    /**
     * Получение компании по ID
     */
    public function getCompany($companyId)
    {
        $companies = $this->readData($this->companiesFile);
        return $companies[$companyId] ?? null;
    }

    /**
     * Получение всех сделок
     */
    public function getAllDeals()
    {
        return $this->readData($this->dealsFile);
    }

    /**
     * Добавление сделки
     */
    public function addDeal($dealData)
    {
        $deals = $this->readData($this->dealsFile);

        $deals[$dealData['ID']] = [
            'id' => $dealData['ID'],
            'title' => $dealData['TITLE'] ?? '',
            'stage' => $dealData['STAGE_ID'] ?? '',
            'opportunity' => $dealData['OPPORTUNITY'] ?? '',
            'currency' => $dealData['CURRENCY_ID'] ?? '',
            'contact_id' => $dealData['CONTACT_ID'] ?? null,
            'company_id' => $dealData['COMPANY_ID'] ?? null,
            'created_at' => $dealData['DATE_CREATE'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->dealsFile, $deals);

        $this->logger->info('Deal data stored locally', ['deal_id' => $dealData['ID']]);

        return true;
    }

    /**
     * Получение всех проектов
     */
    public function getAllProjects()
    {
        return $this->readData($this->projectsFile);
    }

    /**
     * Получение проекта по ID
     */
    public function getProject($projectId)
    {
        $projects = $this->readData($this->projectsFile);
        return $projects[$projectId] ?? null;
    }

    /**
     * Добавление/обновление проекта
     */
    public function addProject($projectData)
    {
        $projects = $this->readData($this->projectsFile);

        $projectId = $projectData['bitrix_id'] ?? $projectData['id'] ?? $projectData['ID'] ?? null;

        $projects[$projectId] = [
            'bitrix_id' => $projectId,
            'organization_name' => $projectData['organization_name'] ?? '',
            'object_name' => $projectData['object_name'] ?? '',
            'system_type' => $projectData['system_type'] ?? '',
            'location' => $projectData['location'] ?? '',
            'implementation_date' => $projectData['implementation_date'] ?? null,
            'status' => $projectData['status'] ?? '',
            'client_id' => $projectData['client_id'] ?? null,
            'manager_id' => $projectData['manager_id'] ?? null,
            'lk_id' => $projectData['lk_id'] ?? null,
            'created_at' => $projectData['created_at'] ?? $projectData['DATE_CREATE'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $projectData['updated_at'] ?? $projectData['DATE_MODIFY'] ?? date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->projectsFile, $projects);

        $this->logger->info('Project data stored locally', ['project_id' => $projectId]);

        return true;
    }

    /**
     * Получение всех менеджеров
     */
    public function getAllManagers()
    {
        return $this->readData($this->managersFile);
    }

    /**
     * Получение менеджера по ID
     */
    public function getManager($managerId)
    {
        $managers = $this->readData($this->managersFile);
        return $managers[$managerId] ?? null;
    }

    /**
     * Добавление/обновление менеджера
     */
    public function addManager($managerData)
    {
        $managers = $this->readData($this->managersFile);

        $managers[$managerData['ID']] = [
            'bitrix_id' => $managerData['ID'],
            'name' => $managerData['name'] ?? '',
            'last_name' => $managerData['last_name'] ?? '',
            'email' => $managerData['email'] ?? '',
            'phone' => $managerData['phone'] ?? '',
            'position' => $managerData['position'] ?? '',
            'photo' => $managerData['photo'] ?? '',
            'created_at' => $managerData['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $managerData['updated_at'] ?? date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->managersFile, $managers);

        $this->logger->info('Manager data stored locally', ['manager_id' => $managerData['ID']]);

        return true;
    }
}
