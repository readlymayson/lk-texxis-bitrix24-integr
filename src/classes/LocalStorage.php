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
    private $projectsFile;
    private $managersFile;

    public function __construct($logger)
    {
        $this->logger = $logger;
        $this->dataDir = __DIR__ . '/../data';
        $this->contactsFile = $this->dataDir . '/contacts.json';
        $this->companiesFile = $this->dataDir . '/companies.json';
        $this->dealsFile = $this->dataDir . '/deals.json';
        $this->projectsFile = $this->dataDir . '/projects.json';
        $this->managersFile = $this->dataDir . '/managers.json';
        $this->projectsFile = $this->dataDir . '/projects.json';
        $this->managersFile = $this->dataDir . '/managers.json';

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

        // Сохраняем информацию из Bitrix24 согласно маппингу
        $lkData = [
            'id' => $lkId,
            'bitrix_id' => $contactData['ID'],
            'name' => $contactData['NAME'] ?? '',
            'last_name' => $contactData['LAST_NAME'] ?? '',
            'second_name' => $contactData['SECOND_NAME'] ?? '',
            'email' => $contactData['EMAIL'] ?? '',
            'phone' => $contactData['PHONE'] ?? '',
            'type_id' => $contactData['TYPE_ID'] ?? '',
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
     * Создание компании
     */
    public function createCompany($companyData)
    {
        // Извлекаем ID компании, проверяя разные возможные форматы
        $companyId = $companyData['ID'] ?? $companyData['id'] ?? null;
        
        if (empty($companyId)) {
            $this->logger->error('Company ID is missing in company data', [
                'data_keys' => array_keys($companyData),
                'has_id' => isset($companyData['ID']),
                'has_lowercase_id' => isset($companyData['id'])
            ]);
            return false;
        }

        $this->logger->info('Creating company locally', ['company_id' => $companyId]);

        $companies = $this->readData($this->companiesFile);

        $companies[$companyId] = [
            'id' => $companyId,
            'title' => $companyData['TITLE'] ?? $companyData['title'] ?? '',
            'email' => $companyData['EMAIL'] ?? $companyData['email'] ?? '',
            'phone' => $companyData['PHONE'] ?? $companyData['phone'] ?? '',
            'type' => $companyData['COMPANY_TYPE'] ?? $companyData['company_type'] ?? '',
            'industry' => $companyData['INDUSTRY'] ?? $companyData['industry'] ?? '',
            'employees' => $companyData['EMPLOYEES'] ?? $companyData['employees'] ?? '',
            'revenue' => $companyData['REVENUE'] ?? $companyData['revenue'] ?? '',
            'address' => $companyData['ADDRESS'] ?? $companyData['address'] ?? '',
            'contact_id' => $companyData['CONTACT_ID'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->companiesFile, $companies);

        $this->logger->info('Company created successfully', ['company_id' => $companyId]);

        return true;
    }

    /**
     * Синхронизация данных контакта по Bitrix ID
     */
    public function syncContactByBitrixId($contactId, $contactData)
    {
        // Находим контакт по Bitrix ID
        $existingContact = $this->getContact($contactId);

        if (!$existingContact) {
            $this->logger->warning('Contact not found for sync by Bitrix ID', [
                'contact_id' => $contactId
            ]);
            return false;
        }

        // Используем LK ID для синхронизации
        return $this->syncContact($existingContact['id'], $contactData);
    }

    /**
     * Синхронизация данных контакта
     */
    public function syncContact($lkId, $contactData)
    {
        $this->logger->info('Syncing contact data locally - START', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID'],
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

        // Обновляем данные контакта согласно маппингу
        $contacts[$contactData['ID']]['name'] = $contactData['NAME'] ?? $contacts[$contactData['ID']]['name'];
        $contacts[$contactData['ID']]['last_name'] = $contactData['LAST_NAME'] ?? $contacts[$contactData['ID']]['last_name'];
        $contacts[$contactData['ID']]['second_name'] = $contactData['SECOND_NAME'] ?? $contacts[$contactData['ID']]['second_name'];
        $contacts[$contactData['ID']]['email'] = $contactData['EMAIL'] ?? $contacts[$contactData['ID']]['email'];
        $contacts[$contactData['ID']]['phone'] = $contactData['PHONE'] ?? $contacts[$contactData['ID']]['phone'];
        $contacts[$contactData['ID']]['type_id'] = $contactData['TYPE_ID'] ?? $contacts[$contactData['ID']]['type_id'];
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
    public function syncCompany($lkId, $companyData)
    {
        $this->logger->info('Syncing company data locally', ['lk_id' => $lkId, 'company_id' => $companyData['ID']]);

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
            'contact_id' => $companyData['CONTACT_ID'] ?? null,
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
     * Получение всех проектов
     */
    public function getAllProjects()
    {
        return $this->readData($this->projectsFile);
    }

    /**
     * Получение всех менеджеров
     */
    public function getAllManagers()
    {
        return $this->readData($this->managersFile);
    }

    /**
     * Добавление проекта
     */
    public function addProject($projectData)
    {
        $this->logger->info('Adding project locally', ['project_id' => $projectData['id'] ?? $projectData['ID'] ?? 'unknown']);

        $projects = $this->readData($this->projectsFile);

        $projectId = $projectData['id'] ?? $projectData['ID'] ?? $projectData['bitrix_id'] ?? null;
        if (!$projectId) {
            $this->logger->error('Project ID not found in data', ['project_data_keys' => array_keys($projectData)]);
            return false;
        }

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
            'created_at' => $projectData['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];
        
        $this->logger->debug('Project data being saved', [
            'project_id' => $projectId,
            'organization_name' => $projects[$projectId]['organization_name'],
            'client_id' => $projects[$projectId]['client_id'],
            'status' => $projects[$projectId]['status']
        ]);

        $this->writeData($this->projectsFile, $projects);

        $this->logger->info('Project added successfully', ['project_id' => $projectId]);

        return true;
    }

    /**
     * Добавление менеджера
     */
    public function addManager($managerData)
    {
        $this->logger->info('Adding manager locally', ['manager_id' => $managerData['ID'] ?? 'unknown']);

        $managers = $this->readData($this->managersFile);

        $managerId = $managerData['ID'] ?? $managerData['bitrix_id'] ?? null;
        if (!$managerId) {
            $this->logger->error('Manager ID not found in data', ['manager_data_keys' => array_keys($managerData)]);
            return false;
        }

        $managers[$managerId] = [
            'bitrix_id' => $managerId,
            'name' => $managerData['NAME'] ?? '',
            'last_name' => $managerData['LAST_NAME'] ?? '',
            'email' => $managerData['EMAIL'] ?? '',
            'phone' => $managerData['PHONE'] ?? '',
            'position' => $managerData['WORK_POSITION'] ?? '',
            'photo' => $managerData['PERSONAL_PHOTO'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->managersFile, $managers);

        $this->logger->info('Manager added successfully', ['manager_id' => $managerId]);

        return true;
    }

    /**
     * Синхронизация данных компании по Bitrix ID
     */
    public function syncCompanyByBitrixId($companyId, $companyData)
    {
        // Находим компанию по Bitrix ID
        $existingCompany = $this->getCompany($companyId);

        if (!$existingCompany) {
            $this->logger->warning('Company not found for sync by Bitrix ID, creating new', [
                'company_id' => $companyId
            ]);
            return $this->createCompany($companyData);
        }

        // Используем company ID для синхронизации
        return $this->syncCompany($companyId, $companyData);
    }

    /**
     * Синхронизация данных проекта по Bitrix ID
     */
    public function syncProjectByBitrixId($projectId, $projectData)
    {
        $this->logger->info('Syncing project by Bitrix ID', ['project_id' => $projectId]);

        $projects = $this->readData($this->projectsFile);

        if (!isset($projects[$projectId])) {
            $this->logger->warning('Project not found for sync by Bitrix ID, creating new', [
                'project_id' => $projectId
            ]);
            return $this->addProject($projectData);
        }

        // Обновляем данные проекта
        $projects[$projectId]['organization_name'] = $projectData['organization_name'] ?? $projects[$projectId]['organization_name'] ?? '';
        $projects[$projectId]['object_name'] = $projectData['object_name'] ?? $projects[$projectId]['object_name'] ?? '';
        $projects[$projectId]['system_type'] = $projectData['system_type'] ?? $projects[$projectId]['system_type'] ?? '';
        $projects[$projectId]['location'] = $projectData['location'] ?? $projects[$projectId]['location'] ?? '';
        $projects[$projectId]['implementation_date'] = $projectData['implementation_date'] ?? $projects[$projectId]['implementation_date'] ?? null;
        $projects[$projectId]['status'] = $projectData['status'] ?? $projects[$projectId]['status'] ?? 'NEW';
        $projects[$projectId]['client_id'] = $projectData['client_id'] ?? $projects[$projectId]['client_id'] ?? null;
        $projects[$projectId]['manager_id'] = $projectData['manager_id'] ?? $projects[$projectId]['manager_id'] ?? null;
        $projects[$projectId]['updated_at'] = date('Y-m-d H:i:s');
        
        $this->logger->debug('Project data being updated', [
            'project_id' => $projectId,
            'organization_name' => $projects[$projectId]['organization_name'],
            'client_id' => $projects[$projectId]['client_id'],
            'status' => $projects[$projectId]['status']
        ]);

        $this->writeData($this->projectsFile, $projects);

        $this->logger->info('Project synced successfully', ['project_id' => $projectId]);

        return true;
    }

    /**
     * Синхронизация данных менеджера по Bitrix ID
     */
    public function syncManagerByBitrixId($managerId, $managerData)
    {
        $this->logger->info('Syncing manager by Bitrix ID', ['manager_id' => $managerId]);

        $managers = $this->readData($this->managersFile);

        if (!isset($managers[$managerId])) {
            $this->logger->warning('Manager not found for sync by Bitrix ID, creating new', [
                'manager_id' => $managerId
            ]);
            return $this->addManager($managerData);
        }

        // Обновляем данные менеджера
        $managers[$managerId]['name'] = $managerData['NAME'] ?? $managers[$managerId]['name'];
        $managers[$managerId]['last_name'] = $managerData['LAST_NAME'] ?? $managers[$managerId]['last_name'];
        $managers[$managerId]['email'] = $managerData['EMAIL'] ?? $managers[$managerId]['email'];
        $managers[$managerId]['phone'] = $managerData['PHONE'] ?? $managers[$managerId]['phone'];
        $managers[$managerId]['position'] = $managerData['WORK_POSITION'] ?? $managers[$managerId]['position'];
        $managers[$managerId]['photo'] = $managerData['PERSONAL_PHOTO'] ?? $managers[$managerId]['photo'];
        $managers[$managerId]['updated_at'] = date('Y-m-d H:i:s');

        $this->writeData($this->managersFile, $managers);

        $this->logger->info('Manager synced successfully', ['manager_id' => $managerId]);

        return true;
    }

    /**
     * Синхронизация данных сделки по Bitrix ID
     */
    public function syncDealByBitrixId($dealId, $dealData)
    {
        $this->logger->info('Syncing deal by Bitrix ID', ['deal_id' => $dealId]);

        $deals = $this->readData($this->dealsFile);

        if (!isset($deals[$dealId])) {
            $this->logger->warning('Deal not found for sync by Bitrix ID, creating new', [
                'deal_id' => $dealId
            ]);
            return $this->addDeal($dealData);
        }

        // Обновляем данные сделки
        $deals[$dealId]['title'] = $dealData['TITLE'] ?? ($deals[$dealId]['title'] ?? '');
        $deals[$dealId]['stage'] = $dealData['STAGE_ID'] ?? ($deals[$dealId]['stage'] ?? '');
        $deals[$dealId]['opportunity'] = $dealData['OPPORTUNITY'] ?? ($deals[$dealId]['opportunity'] ?? '');
        $deals[$dealId]['currency'] = $dealData['CURRENCY_ID'] ?? ($deals[$dealId]['currency'] ?? '');
        $deals[$dealId]['contact_id'] = $dealData['CONTACT_ID'] ?? ($deals[$dealId]['contact_id'] ?? null);
        $deals[$dealId]['company_id'] = $dealData['COMPANY_ID'] ?? ($deals[$dealId]['company_id'] ?? null);
        $deals[$dealId]['updated_at'] = date('Y-m-d H:i:s');

        $this->writeData($this->dealsFile, $deals);

        $this->logger->info('Deal synced successfully', ['deal_id' => $dealId]);

        return true;
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
     * Удаление всех данных контакта из базы данных
     * Удаляет контакт и все связанные с ним сущности: компании, сделки и проекты
     * 
     * @param string $contactId ID контакта в Bitrix24
     * @return bool true при успешном удалении, false при ошибке
     */
    public function deleteContactData($contactId)
    {
        $this->logger->info('Starting deletion of contact data and all related entities', ['contact_id' => $contactId]);

        // Приводим contactId к строке для корректного сравнения
        $contactId = (string)$contactId;
        
        $deletedCompanies = [];
        $deletedDeals = [];
        $deletedProjects = [];
        $contactDeleted = false;

        // Удаляем контакт
        $contacts = $this->readData($this->contactsFile);
        if (isset($contacts[$contactId])) {
            unset($contacts[$contactId]);
            $writeResult = $this->writeData($this->contactsFile, $contacts);
            if ($writeResult !== false) {
                $contactDeleted = true;
                $this->logger->info('Contact deleted from local storage', ['contact_id' => $contactId]);
            } else {
                $this->logger->error('Failed to write contacts file after deletion', ['contact_id' => $contactId]);
            }
        } else {
            $this->logger->warning('Contact not found in local storage', ['contact_id' => $contactId]);
        }

        // Удаляем связанные компании
        $companies = $this->readData($this->companiesFile);
        foreach ($companies as $companyId => $company) {
            $companyContactId = $company['contact_id'] ?? null;
            
            // Проверяем связь через contact_id (может быть строкой, числом или массивом)
            $shouldDelete = false;
            if (is_array($companyContactId)) {
                // Если это массив, проверяем наличие contactId в массиве
                $shouldDelete = in_array($contactId, array_map('strval', $companyContactId), true);
            } else {
                // Приводим к строке для сравнения
                $shouldDelete = ((string)$companyContactId === $contactId);
            }
            
            if ($shouldDelete) {
                unset($companies[$companyId]);
                $deletedCompanies[] = $companyId;
            }
        }
        if (!empty($deletedCompanies)) {
            $writeResult = $this->writeData($this->companiesFile, $companies);
            if ($writeResult !== false) {
                $this->logger->info('Related companies deleted', [
                    'contact_id' => $contactId,
                    'deleted_companies' => $deletedCompanies,
                    'count' => count($deletedCompanies)
                ]);
            } else {
                $this->logger->error('Failed to write companies file after deletion', [
                    'contact_id' => $contactId,
                    'deleted_companies' => $deletedCompanies
                ]);
            }
        }

        // Удаляем связанные сделки
        $deals = $this->readData($this->dealsFile);
        foreach ($deals as $dealId => $deal) {
            $dealContactId = $deal['contact_id'] ?? null;
            // Приводим к строке для сравнения
            if ((string)$dealContactId === $contactId) {
                unset($deals[$dealId]);
                $deletedDeals[] = $dealId;
            }
        }
        if (!empty($deletedDeals)) {
            $writeResult = $this->writeData($this->dealsFile, $deals);
            if ($writeResult !== false) {
                $this->logger->info('Related deals deleted', [
                    'contact_id' => $contactId,
                    'deleted_deals' => $deletedDeals,
                    'count' => count($deletedDeals)
                ]);
            } else {
                $this->logger->error('Failed to write deals file after deletion', [
                    'contact_id' => $contactId,
                    'deleted_deals' => $deletedDeals
                ]);
            }
        }

        // Удаляем связанные проекты
        $projects = $this->readData($this->projectsFile);
        foreach ($projects as $projectId => $project) {
            $projectClientId = $project['client_id'] ?? null;
            // Приводим к строке для сравнения
            if ((string)$projectClientId === $contactId) {
                unset($projects[$projectId]);
                $deletedProjects[] = $projectId;
            }
        }
        if (!empty($deletedProjects)) {
            $writeResult = $this->writeData($this->projectsFile, $projects);
            if ($writeResult !== false) {
                $this->logger->info('Related projects deleted', [
                    'contact_id' => $contactId,
                    'deleted_projects' => $deletedProjects,
                    'count' => count($deletedProjects)
                ]);
            } else {
                $this->logger->error('Failed to write projects file after deletion', [
                    'contact_id' => $contactId,
                    'deleted_projects' => $deletedProjects
                ]);
            }
        }

        $totalDeleted = count($deletedCompanies) + count($deletedDeals) + count($deletedProjects);
        
        $this->logger->info('Contact data deletion completed', [
            'contact_id' => $contactId,
            'contact_deleted' => $contactDeleted,
            'companies_deleted' => count($deletedCompanies),
            'deals_deleted' => count($deletedDeals),
            'projects_deleted' => count($deletedProjects),
            'total_related_entities_deleted' => $totalDeleted
        ]);

        return true;
    }
}
