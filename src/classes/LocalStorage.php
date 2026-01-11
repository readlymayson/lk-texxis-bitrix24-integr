<?php

/**
 * Класс для локального хранения данных интеграции с Битрикс24
 * Заменяет API личного кабинета локальным хранением
 */
class LocalStorage
{
    private $logger;
    private $config;
    private $dataDir;
    private $contactsFile;
    private $companiesFile;
    private $projectsFile;
    private $managersFile;
    private $cachedData = [
        'contacts' => null,
        'companies' => null,
        'projects' => null,
        'managers' => null
    ];

    public function __construct($logger, $config = null)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->dataDir = __DIR__ . '/../data';
        $this->contactsFile = $this->dataDir . '/contacts.json';
        $this->companiesFile = $this->dataDir . '/companies.json';
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
     * Чтение данных из файла с кэшированием
     */
    private function readData($file, $cacheKey = null)
    {
        if ($cacheKey && isset($this->cachedData[$cacheKey]) && $this->cachedData[$cacheKey] !== null) {
            return $this->cachedData[$cacheKey];
        }

        if (!file_exists($file)) {
            return [];
        }

        $data = json_decode(file_get_contents($file), true);
        $result = $data ?: [];

        if ($cacheKey) {
            $this->cachedData[$cacheKey] = $result;
        }

        return $result;
    }

    /**
     * Запись данных в файл
     */
    private function writeData($file, $data, $cacheKey = null)
    {
        $jsonData = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if ($jsonData === false) {
            $this->logger->error('JSON encoding failed', [
                'file' => basename($file),
                'json_error' => json_last_error_msg()
            ]);
            return false;
        }

        $writeResult = file_put_contents($file, $jsonData);

        if ($writeResult === false) {
            $this->logger->error('File write failed', [
                'file' => basename($file),
                'file_writable' => is_writable($file),
                'disk_free_space' => disk_free_space(dirname($file)) ?? 'unknown'
            ]);
            return false;
        }

        if ($cacheKey) {
            $this->cachedData[$cacheKey] = $data;
        }

        $this->logger->debug('File write successful', [
            'file' => basename($file),
            'bytes_written' => $writeResult
        ]);

        return $writeResult;
    }

    /**
     * Создание личного кабинета для контакта
     */
    public function createLK($contactData)
    {
        $this->logger->debug('Creating local LK for contact', ['contact_id' => $contactData['ID']]);

        $contacts = $this->readData($this->contactsFile, 'contacts');

        $lkId = 'LK-' . time() . '-' . $contactData['ID'];

        // Получаем значение поля агентского договора
        $agentContractValue = $this->getFieldValue($contactData, 'contact', 'agent_contract_status');
        
        // Получаем значение поля ЛК клиента
        $lkClientValue = $this->getFieldValue($contactData, 'contact', 'lk_client_field');

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
            'agent_contract_status' => $agentContractValue,
            'lk_client_field' => $lkClientValue,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $contacts[$contactData['ID']] = $lkData;
        $this->writeData($this->contactsFile, $contacts, 'contacts');

        $this->logger->info('Local LK created successfully', ['lk_id' => $lkId, 'contact_id' => $contactData['ID']]);

        return true;
    }

    /**
     * Создание компании
     */
    public function createCompany($companyData)
    {
        $companyId = $companyData['ID'] ?? $companyData['id'] ?? null;
        
        if (empty($companyId)) {
            $this->logger->error('Company ID is missing in company data', [
                'data_keys' => array_keys($companyData),
                'has_id' => isset($companyData['ID']),
                'has_lowercase_id' => isset($companyData['id'])
            ]);
            return false;
        }

        $this->logger->debug('Creating company locally', ['company_id' => $companyId]);

        $companies = $this->readData($this->companiesFile, 'companies');

        // Получаем значения полей из маппинга
        $partnerContractValue = $this->getFieldValue($companyData, 'company', 'partner_contract_status');
        $innValue = $this->getFieldValue($companyData, 'company', 'inn');

        $companies[$companyId] = [
            'id' => $companyId,
            'title' => $companyData['TITLE'] ?? $companyData['title'] ?? '',
            'email' => $companyData['EMAIL'] ?? $companyData['email'] ?? '',
            'phone' => $companyData['PHONE'] ?? $companyData['phone'] ?? '',
            'inn' => $innValue,
            'type' => $companyData['COMPANY_TYPE'] ?? $companyData['company_type'] ?? '',
            'industry' => $companyData['INDUSTRY'] ?? $companyData['industry'] ?? '',
            'employees' => $companyData['EMPLOYEES'] ?? $companyData['employees'] ?? '',
            'revenue' => $companyData['REVENUE'] ?? $companyData['revenue'] ?? '',
            'address' => $companyData['ADDRESS'] ?? $companyData['address'] ?? '',
            'contact_id' => $companyData['CONTACT_ID'] ?? null,
            'partner_contract_status' => $partnerContractValue,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $this->writeData($this->companiesFile, $companies, 'companies');

        $this->logger->info('Company created successfully', ['company_id' => $companyId]);

        return true;
    }

    /**
     * Синхронизация данных контакта по Bitrix ID
     */
    public function syncContactByBitrixId($contactId, $contactData)
    {
        $existingContact = $this->getContact($contactId);

        if (!$existingContact) {
            $this->logger->warning('Contact not found for sync by Bitrix ID', [
                'contact_id' => $contactId
            ]);
            return false;
        }

        return $this->syncContact($existingContact['id'], $contactData);
    }

    /**
     * Синхронизация данных контакта
     */
    public function syncContact($lkId, $contactData)
    {
        $this->logger->debug('Syncing contact data locally', [
            'lk_id' => $lkId,
            'contact_id' => $contactData['ID']
        ]);

        $contacts = $this->readData($this->contactsFile, 'contacts');

        if (!isset($contacts[$contactData['ID']])) {
            $this->logger->warning('Contact not found in local storage, creating new', [
                'contact_id' => $contactData['ID'],
                'available_contacts' => array_keys($contacts)
            ]);
            return $this->createLK($contactData);
        }

        $oldData = $contacts[$contactData['ID']];
        $this->logger->debug('Contact found, preparing update', [
            'contact_id' => $contactData['ID']
        ]);

        $contacts[$contactData['ID']]['name'] = $contactData['NAME'] ?? $contacts[$contactData['ID']]['name'];
        $contacts[$contactData['ID']]['last_name'] = $contactData['LAST_NAME'] ?? $contacts[$contactData['ID']]['last_name'];
        $contacts[$contactData['ID']]['second_name'] = $contactData['SECOND_NAME'] ?? $contacts[$contactData['ID']]['second_name'];
        $contacts[$contactData['ID']]['email'] = $contactData['EMAIL'] ?? $contacts[$contactData['ID']]['email'];
        $contacts[$contactData['ID']]['phone'] = $contactData['PHONE'] ?? $contacts[$contactData['ID']]['phone'];
        $contacts[$contactData['ID']]['type_id'] = $contactData['TYPE_ID'] ?? $contacts[$contactData['ID']]['type_id'];
        $contacts[$contactData['ID']]['company'] = $contactData['COMPANY_ID'] ?? $contacts[$contactData['ID']]['company'];
        
        // Обновляем поле агентского договора
        $agentContractValue = $this->getFieldValue($contactData, 'contact', 'agent_contract_status');
        if ($agentContractValue !== null) {
            $contacts[$contactData['ID']]['agent_contract_status'] = $agentContractValue;
        }
        
        // Обновляем поле ЛК клиента
        $lkClientValue = $this->getFieldValue($contactData, 'contact', 'lk_client_field');
        if ($lkClientValue !== null) {
            $contacts[$contactData['ID']]['lk_client_field'] = $lkClientValue;
        }
        
        $newUpdatedAt = date('Y-m-d H:i:s');
        $contacts[$contactData['ID']]['updated_at'] = $newUpdatedAt;

        $this->logger->debug('Contact data updated in memory', [
            'contact_id' => $contactData['ID']
        ]);

        $writeResult = $this->writeData($this->contactsFile, $contacts, 'contacts');
        
        if ($writeResult === false) {
            $this->logger->error('Failed to write contact data', ['contact_id' => $contactData['ID']]);
        } else {
            $this->logger->info('Contact synced successfully', ['contact_id' => $contactData['ID']]);
        }

        return true;
    }

    /**
     * Синхронизация данных компании
     */
    public function syncCompany($lkId, $companyData)
    {
        $this->logger->debug('Syncing company data locally', ['lk_id' => $lkId, 'company_id' => $companyData['ID']]);

        $companies = $this->readData($this->companiesFile, 'companies');

        // Получаем значения полей из маппинга
        $partnerContractValue = $this->getFieldValue($companyData, 'company', 'partner_contract_status');
        $innValue = $this->getFieldValue($companyData, 'company', 'inn');

        $companies[$companyData['ID']] = [
            'id' => $companyData['ID'],
            'title' => $companyData['TITLE'] ?? '',
            'email' => $companyData['EMAIL'] ?? '',
            'phone' => $companyData['PHONE'] ?? '',
            'inn' => $innValue,
            'industry' => $companyData['INDUSTRY'] ?? '',
            'employees' => $companyData['EMPLOYEES'] ?? '',
            'revenue' => $companyData['REVENUE'] ?? '',
            'address' => $companyData['ADDRESS'] ?? '',
            'contact_id' => $companyData['CONTACT_ID'] ?? null,
            'partner_contract_status' => $partnerContractValue,
            'created_at' => $companyData['DATE_CREATE'] ?? date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $writeResult = $this->writeData($this->companiesFile, $companies, 'companies');

        if ($writeResult === false) {
            $this->logger->error('Failed to write company data', ['company_id' => $companyData['ID']]);
        } else {
            $this->logger->info('Company synced successfully', ['company_id' => $companyData['ID']]);
        }

        return true;
    }

    /**
     * Получение всех контактов
     */
    public function getAllContacts()
    {
        return $this->readData($this->contactsFile, 'contacts');
    }

    /**
     * Получение последнего обновленного контакта
     */
    public function getLastUpdatedContact()
    {
        $contacts = $this->readData($this->contactsFile, 'contacts');

        if (empty($contacts)) {
            return null;
        }

        uasort($contacts, function($a, $b) {
            return strtotime($b['updated_at'] ?? $b['created_at']) <=> strtotime($a['updated_at'] ?? $a['created_at']);
        });

        return reset($contacts);
    }

    /**
     * Получение контактов отсортированных по времени обновления
     */
    public function getContactsSortedByUpdate($limit = null)
    {
        $contacts = $this->readData($this->contactsFile, 'contacts');

        if (empty($contacts)) {
            return [];
        }

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
        $contacts = $this->readData($this->contactsFile, 'contacts');
        return $contacts[$contactId] ?? null;
    }

    /**
     * Получение контакта по email
     */
    public function getContactByEmail($email)
    {
        $contacts = $this->readData($this->contactsFile, 'contacts');

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
        return $this->readData($this->companiesFile, 'companies');
    }

    /**
     * Получение компании по ID
     */
    public function getCompany($companyId)
    {
        $companies = $this->readData($this->companiesFile, 'companies');
        return $companies[$companyId] ?? null;
    }

    /**
     * Получение всех проектов
     */
    public function getAllProjects()
    {
        return $this->readData($this->projectsFile, 'projects');
    }

    /**
     * Получение всех менеджеров
     */
    public function getAllManagers()
    {
        return $this->readData($this->managersFile, 'managers');
    }

    /**
     * Добавление проекта
     */
    public function addProject($projectData)
    {
        $this->logger->debug('Adding project locally', ['project_id' => $projectData['id'] ?? $projectData['ID'] ?? 'unknown']);

        $projects = $this->readData($this->projectsFile, 'projects');

        $projectId = $projectData['id'] ?? $projectData['ID'] ?? $projectData['bitrix_id'] ?? null;
        if (!$projectId) {
            $this->logger->error('Project ID not found in data', ['project_data_keys' => array_keys($projectData)]);
            return false;
        }

        // Обработка equipment_list - всегда массив файлов
        $equipmentList = $projectData['equipment_list'] ?? [];
        if (!is_array($equipmentList)) {
            // Если это не массив, преобразуем в массив (для обратной совместимости)
            $equipmentList = !empty($equipmentList) ? [$equipmentList] : [];
        }

        $projects[$projectId] = [
            'bitrix_id' => $projectId,
            'organization_name' => $projectData['organization_name'] ?? '',
            'object_name' => $projectData['object_name'] ?? '',
            'system_types' => is_array($projectData['system_types'] ?? null) ? $projectData['system_types'] : (!empty($projectData['system_types']) ? [$projectData['system_types']] : []),
            'location' => $projectData['location'] ?? '',
            'implementation_date' => $projectData['implementation_date'] ?? null,
            'request_type' => $projectData['request_type'] ?? '',
            'equipment_list' => $equipmentList,
            'competitors' => $projectData['competitors'] ?? '',
            'marketing_discount' => $projectData['marketing_discount'] ?? false,
            'technical_description' => $projectData['technical_description'] ?? '',
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

        $writeResult = $this->writeData($this->projectsFile, $projects, 'projects');

        if ($writeResult === false) {
            $this->logger->error('Failed to write project data', ['project_id' => $projectId]);
        } else {
            $this->logger->info('Project added successfully', ['project_id' => $projectId]);
        }

        return true;
    }

    /**
     * Добавление менеджера
     */
    public function addManager($managerData)
    {
        $this->logger->debug('Adding manager locally', ['manager_id' => $managerData['ID'] ?? 'unknown']);

        $managers = $this->readData($this->managersFile, 'managers');

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
            'phone' => $this->extractManagerPhone($managerData),
            'position' => $managerData['WORK_POSITION'] ?? '',
            'photo' => $this->extractManagerPhoto($managerData),
            'messengers' => $this->extractManagerMessengers($managerData),
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'bitrix24_webhook'
        ];

        $writeResult = $this->writeData($this->managersFile, $managers, 'managers');

        if ($writeResult === false) {
            $this->logger->error('Failed to write manager data', ['manager_id' => $managerId]);
        } else {
            $this->logger->info('Manager added successfully', ['manager_id' => $managerId]);
        }

        return true;
    }

    /**
     * Синхронизация данных компании по Bitrix ID
     */
    public function syncCompanyByBitrixId($companyId, $companyData)
    {
        $existingCompany = $this->getCompany($companyId);

        if (!$existingCompany) {
            $this->logger->warning('Company not found for sync by Bitrix ID, creating new', [
                'company_id' => $companyId
            ]);
            return $this->createCompany($companyData);
        }

        return $this->syncCompany($companyId, $companyData);
    }

    /**
     * Синхронизация данных проекта по Bitrix ID
     */
    public function syncProjectByBitrixId($projectId, $projectData)
    {
        $this->logger->debug('Syncing project by Bitrix ID', ['project_id' => $projectId]);

        $projects = $this->readData($this->projectsFile, 'projects');

        if (!isset($projects[$projectId])) {
            $this->logger->warning('Project not found for sync by Bitrix ID, creating new', [
                'project_id' => $projectId
            ]);
            return $this->addProject($projectData);
        }

        $projects[$projectId]['organization_name'] = $projectData['organization_name'] ?? $projects[$projectId]['organization_name'] ?? '';
        $projects[$projectId]['object_name'] = $projectData['object_name'] ?? $projects[$projectId]['object_name'] ?? '';
        $systemTypesValue = $projectData['system_types'] ?? $projects[$projectId]['system_types'] ?? [];
        $projects[$projectId]['system_types'] = is_array($systemTypesValue) ? $systemTypesValue : (!empty($systemTypesValue) ? [$systemTypesValue] : []);
        $projects[$projectId]['location'] = $projectData['location'] ?? $projects[$projectId]['location'] ?? '';
        $projects[$projectId]['implementation_date'] = $projectData['implementation_date'] ?? $projects[$projectId]['implementation_date'] ?? null;
        $projects[$projectId]['request_type'] = $projectData['request_type'] ?? $projects[$projectId]['request_type'] ?? '';
        
        // Обработка equipment_list - всегда массив файлов
        $equipmentList = $projectData['equipment_list'] ?? $projects[$projectId]['equipment_list'] ?? [];
        if (!is_array($equipmentList)) {
            // Если это не массив, преобразуем в массив (для обратной совместимости)
            $equipmentList = !empty($equipmentList) ? [$equipmentList] : [];
        }
        $projects[$projectId]['equipment_list'] = $equipmentList;
        
        $projects[$projectId]['competitors'] = $projectData['competitors'] ?? $projects[$projectId]['competitors'] ?? '';
        $projects[$projectId]['marketing_discount'] = $projectData['marketing_discount'] ?? $projects[$projectId]['marketing_discount'] ?? false;
        $projects[$projectId]['technical_description'] = $projectData['technical_description'] ?? $projects[$projectId]['technical_description'] ?? '';
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

        $writeResult = $this->writeData($this->projectsFile, $projects, 'projects');

        if ($writeResult === false) {
            $this->logger->error('Failed to write project data', ['project_id' => $projectId]);
        } else {
            $this->logger->info('Project synced successfully', ['project_id' => $projectId]);
        }

        return true;
    }

    /**
     * Синхронизация данных менеджера по Bitrix ID
     */
    public function syncManagerByBitrixId($managerId, $managerData)
    {
        $this->logger->debug('Syncing manager by Bitrix ID', ['manager_id' => $managerId]);

        $managers = $this->readData($this->managersFile, 'managers');

        if (!isset($managers[$managerId])) {
            $this->logger->warning('Manager not found for sync by Bitrix ID, creating new', [
                'manager_id' => $managerId
            ]);
            return $this->addManager($managerData);
        }

        $managers[$managerId]['name'] = $managerData['NAME'] ?? $managers[$managerId]['name'];
        $managers[$managerId]['last_name'] = $managerData['LAST_NAME'] ?? $managers[$managerId]['last_name'];
        $managers[$managerId]['email'] = $managerData['EMAIL'] ?? $managers[$managerId]['email'];
        $managers[$managerId]['phone'] = $this->extractManagerPhone($managerData) ?: ($managers[$managerId]['phone'] ?? '');
        $managers[$managerId]['position'] = $managerData['WORK_POSITION'] ?? $managers[$managerId]['position'];
        $managers[$managerId]['photo'] = $this->extractManagerPhoto($managerData) ?: ($managers[$managerId]['photo'] ?? '');
        $messengers = $this->extractManagerMessengers($managerData);
        if (!empty($messengers)) {
            $managers[$managerId]['messengers'] = $messengers;
        } elseif (!isset($managers[$managerId]['messengers'])) {
            $managers[$managerId]['messengers'] = [];
        }
        $managers[$managerId]['updated_at'] = date('Y-m-d H:i:s');

        $writeResult = $this->writeData($this->managersFile, $managers, 'managers');

        if ($writeResult === false) {
            $this->logger->error('Failed to write manager data', ['manager_id' => $managerId]);
        } else {
            $this->logger->info('Manager synced successfully', ['manager_id' => $managerId]);
        }

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
        $this->logger->debug('Starting deletion of contact data and all related entities', ['contact_id' => $contactId]);

        $contactId = (string)$contactId;
        
        $deletedCompanies = [];
        $deletedProjects = [];
        $contactDeleted = false;

        $contacts = $this->readData($this->contactsFile, 'contacts');
        if (isset($contacts[$contactId])) {
            unset($contacts[$contactId]);
            $writeResult = $this->writeData($this->contactsFile, $contacts, 'contacts');
            if ($writeResult !== false) {
                $contactDeleted = true;
                $this->logger->debug('Contact deleted from local storage', ['contact_id' => $contactId]);
            } else {
                $this->logger->error('Failed to write contacts file after deletion', ['contact_id' => $contactId]);
            }
        } else {
            $this->logger->warning('Contact not found in local storage', ['contact_id' => $contactId]);
        }

        // Удаляем связанные компании
        $companies = $this->readData($this->companiesFile, 'companies');
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
            $writeResult = $this->writeData($this->companiesFile, $companies, 'companies');
            if ($writeResult !== false) {
                $this->logger->debug('Related companies deleted', [
                    'contact_id' => $contactId,
                    'count' => count($deletedCompanies)
                ]);
            } else {
                $this->logger->error('Failed to write companies file after deletion', [
                    'contact_id' => $contactId,
                    'deleted_companies' => $deletedCompanies
                ]);
            }
        }

        $projects = $this->readData($this->projectsFile, 'projects');
        foreach ($projects as $projectId => $project) {
            $projectClientId = $project['client_id'] ?? null;
            if ((string)$projectClientId === $contactId) {
                unset($projects[$projectId]);
                $deletedProjects[] = $projectId;
            }
        }
        if (!empty($deletedProjects)) {
            $writeResult = $this->writeData($this->projectsFile, $projects, 'projects');
            if ($writeResult !== false) {
                $this->logger->debug('Related projects deleted', [
                    'contact_id' => $contactId,
                    'count' => count($deletedProjects)
                ]);
            } else {
                $this->logger->error('Failed to write projects file after deletion', [
                    'contact_id' => $contactId,
                    'deleted_projects' => $deletedProjects
                ]);
            }
        }

        $totalDeleted = count($deletedCompanies) + count($deletedProjects);
        
        $this->logger->info('Contact data deletion completed', [
            'contact_id' => $contactId,
            'contact_deleted' => $contactDeleted,
            'companies_deleted' => count($deletedCompanies),
            'projects_deleted' => count($deletedProjects),
            'total_related_entities_deleted' => $totalDeleted
        ]);

        return true;
    }

    /**
     * Удаление компании из локального хранилища
     * 
     * @param string $companyId ID компании в Bitrix24
     * @return bool true при успешном удалении, false при ошибке
     */
    public function deleteCompany($companyId)
    {
        $this->logger->debug('Starting deletion of company', ['company_id' => $companyId]);

        $companyId = (string)$companyId;
        $companies = $this->readData($this->companiesFile, 'companies');
        
        if (!isset($companies[$companyId])) {
            $this->logger->warning('Company not found in local storage', ['company_id' => $companyId]);
            return false;
        }

        unset($companies[$companyId]);
        $writeResult = $this->writeData($this->companiesFile, $companies, 'companies');
        
        if ($writeResult !== false) {
            $this->logger->info('Company deleted from local storage', ['company_id' => $companyId]);
            return true;
        } else {
            $this->logger->error('Failed to write companies file after deletion', ['company_id' => $companyId]);
            return false;
        }
    }

    /**
     * Удаление проекта из локального хранилища
     * 
     * @param string $projectId ID проекта в Bitrix24
     * @return bool true при успешном удалении, false при ошибке
     */
    public function deleteProject($projectId)
    {
        $this->logger->debug('Starting deletion of project', ['project_id' => $projectId]);

        $projectId = (string)$projectId;
        $projects = $this->readData($this->projectsFile, 'projects');
        
        if (!isset($projects[$projectId])) {
            $this->logger->warning('Project not found in local storage', ['project_id' => $projectId]);
            return false;
        }

        unset($projects[$projectId]);
        $writeResult = $this->writeData($this->projectsFile, $projects, 'projects');
        
        if ($writeResult !== false) {
            $this->logger->info('Project deleted from local storage', ['project_id' => $projectId]);
            return true;
        } else {
            $this->logger->error('Failed to write projects file after deletion', ['project_id' => $projectId]);
            return false;
        }
    }

    /**
     * Извлечение ссылки на фото менеджера по маппингу
     */
    private function extractManagerPhoto($managerData)
    {
        $photoField = $this->config['field_mapping']['user']['photo'] ?? 'PERSONAL_PHOTO';
        return $managerData[$photoField] ?? '';
    }

    /**
     * Извлечение номера телефона менеджера с учетом маппинга
     */
    private function extractManagerPhone($managerData)
    {
        $phoneField = $this->config['field_mapping']['user']['phone'] ?? 'PERSONAL_PHONE';
        return $managerData[$phoneField] ?? ($managerData['PHONE'] ?? '');
    }

    /**
     * Преобразование полей мессенджеров менеджера в удобный формат
     */
    private function extractManagerMessengers($managerData)
    {
        $messengerMapping = $this->config['field_mapping']['user']['messengers'] ?? [];
        $messengers = [];

        foreach ($messengerMapping as $messenger => $fieldCode) {
            if (empty($fieldCode)) {
                continue;
            }

            $rawValue = $managerData[$fieldCode] ?? null;
            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            $messengers[$messenger] = $this->normalizeMessengerLink($messenger, $rawValue);
        }

        return $messengers;
    }

    /**
     * Нормализация ссылок для мессенджеров
     */
    private function normalizeMessengerLink($type, $value)
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (!is_string($value)) {
            return $value;
        }

        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_contains($value, '://')) {
            return $value;
        }

        switch ($type) {
            case 'telegram':
                $username = ltrim($value, '@');
                return 'https://t.me/' . $username;
            case 'whatsapp':
                $digits = preg_replace('/\D+/', '', $value);
                return $digits ? 'https://wa.me/' . $digits : $value;
            case 'viber':
                $digits = preg_replace('/\D+/', '', $value);
                return $digits ? 'viber://chat?number=' . $digits : $value;
            default:
                return $value;
        }
    }

    /**
     * Получение значения поля из данных сущности по маппингу конфигурации
     * 
     * @param array $entityData Данные сущности из Bitrix24
     * @param string $entityType Тип сущности ('contact', 'company', etc.)
     * @param string $fieldKey Ключ поля в маппинге конфигурации
     * @return mixed Значение поля или null
     */
    private function getFieldValue($entityData, $entityType, $fieldKey)
    {
        if (!$this->config || !isset($this->config['field_mapping'][$entityType][$fieldKey])) {
            return null;
        }

        $fieldName = $this->config['field_mapping'][$entityType][$fieldKey];
        
        if (empty($fieldName) || !isset($entityData[$fieldName])) {
            return null;
        }

        return $entityData[$fieldName];
    }
}
