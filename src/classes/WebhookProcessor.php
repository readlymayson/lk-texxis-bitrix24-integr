<?php

/**
 * Процессор для обработки вебхуков Bitrix24
 * Обрабатывает события последовательно через очередь
 */
class WebhookProcessor
{
    private $bitrixAPI;
    private $localStorage;
    private $logger;
    private $config;

    public function __construct($bitrixAPI, $localStorage, $logger, $config)
    {
        $this->bitrixAPI = $bitrixAPI;
        $this->localStorage = $localStorage;
        $this->logger = $logger;
        $this->config = $config;
    }

    /**
     * Обработка события с механизмом повторных попыток
     */
    public function processEventWithRetry($eventName, $webhookData)
    {
        $maxRetries = $this->config['events']['max_retries'];
        $retryDelays = $this->config['events']['retry_delays'];

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->logger->debug('Processing event attempt', [
                    'event' => $eventName,
                    'attempt' => $attempt + 1,
                    'max_attempts' => $maxRetries + 1
                ]);

                $result = $this->processEvent($eventName, $webhookData);

                if ($result) {
                    return true;
                }

                // Если это не последняя попытка, логируем задержку
                // Повторные попытки должны обрабатываться внешней системой (очередь, cron)
                // sleep() не используется, чтобы не блокировать webhook
                if ($attempt < $maxRetries) {
                    $delay = $retryDelays[$attempt] ?? end($retryDelays);
                    $this->logger->warning('Event processing failed, retry recommended', [
                        'attempt' => $attempt + 1,
                        'max_attempts' => $maxRetries + 1,
                        'recommended_delay' => $delay,
                        'event' => $eventName,
                        'note' => 'Retries should be handled by external queue system'
                    ]);
                }

            } catch (Exception $e) {
                $this->logger->error('Exception during event processing', [
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
    private function extractContactId($rawValue)
    {
        if (is_array($rawValue)) {
            return !empty($rawValue) ? (string)$rawValue[0] : null;
        }
        return !empty($rawValue) ? (string)$rawValue : null;
    }

    /**
     * Маппинг данных проекта из Bitrix24 в локальный формат
     */
    private function mapProjectData($projectData, $mapping)
    {
        $projectId = $projectData['id'] ?? $projectData['ID'] ?? null;
        $clientId = $this->extractContactId($projectData[$mapping['client_id']] ?? null);

        // Извлекаем company_id из данных контакта в локальном хранилище
        $companyId = null;
        if (!empty($clientId) && $this->localStorage) {
            $contactData = $this->localStorage->getContact($clientId);
            if ($contactData && isset($contactData['company'])) {
                $companyId = $contactData['company'];
                $this->logger->debug('Extracted company ID from contact data', [
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
    private function hasContactInLocalStorage($contactId)
    {
        if (empty($contactId)) {
            return false;
        }

        $contact = $this->localStorage->getContact($contactId);
        $exists = $contact !== null;

        $this->logger->debug('Checking contact existence in local storage', [
            'contact_id' => $contactId,
            'exists' => $exists
        ]);

        return $exists;
    }

    /**
     * Получение и синхронизация менеджера для контакта
     */
    private function syncManagerForContact($contactId, $assignedById)
    {
        if (empty($assignedById)) {
            return false;
        }

        $this->logger->debug('Fetching manager data for contact', [
            'contact_id' => $contactId,
            'assigned_by_id' => $assignedById
        ]);

        $managerData = $this->bitrixAPI->getEntityData('user', $assignedById);
        if ($managerData && isset($managerData['result'])) {
            $userData = $managerData['result'];
            if (is_array($userData) && isset($userData[0])) {
                $userData = $userData[0];
            }

            $this->localStorage->syncManagerByBitrixId($assignedById, $userData);
            $this->logger->debug('Manager created/updated for contact', [
                'contact_id' => $contactId,
                'manager_id' => $assignedById
            ]);
            return true;
        } else {
            $this->logger->warning('Failed to fetch manager data', [
                'contact_id' => $contactId,
                'assigned_by_id' => $assignedById
            ]);
            return false;
        }
    }

    /**
     * Проверка допустимого значения поля ЛК клиента
     */
    private function isValidLKClientValue($entityType, $entityData)
    {
        if (!isset($this->config['field_mapping'][$entityType]['lk_client_field'])) {
            $this->logger->debug('LK client field not configured for entity type', [
                'entity_type' => $entityType
            ]);
            return false;
        }

        $fieldName = $this->config['field_mapping'][$entityType]['lk_client_field'];
        $allowedValues = $this->config['field_mapping'][$entityType]['lk_client_values'] ?? [];

        $fieldValue = $entityData[$fieldName] ?? null;

        if (empty($fieldValue) && $fieldValue !== '0' && $fieldValue !== 0) {
            $this->logger->debug('LK client field is empty or not set', [
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

        $this->logger->debug('LK client field validation', [
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
    private function shouldDeleteContactData($entityType, $entityData)
    {
        if (!isset($this->config['field_mapping'][$entityType]['lk_client_field'])) {
            return false;
        }

        $fieldName = $this->config['field_mapping'][$entityType]['lk_client_field'];
        $deleteValues = $this->config['field_mapping'][$entityType]['lk_delete_values'] ?? [];

        if (empty($deleteValues) || !is_array($deleteValues)) {
            return false;
        }

        $fieldValue = $entityData[$fieldName] ?? null;

        if (empty($fieldValue) && $fieldValue !== '0' && $fieldValue !== 0) {
            return false;
        }

        // Нормализуем значения для сравнения (приводим к строкам)
        // Bitrix24 может возвращать значения как строки или числа
        $fieldValueNormalized = (string)$fieldValue;
        $deleteValuesNormalized = array_map('strval', $deleteValues);

        $shouldDelete = in_array($fieldValueNormalized, $deleteValuesNormalized, true);

        $this->logger->debug('Checking if contact data should be deleted', [
            'entity_type' => $entityType,
            'field_name' => $fieldName,
            'field_value' => $fieldValue,
            'field_value_type' => gettype($fieldValue),
            'field_value_normalized' => $fieldValueNormalized,
            'delete_values' => $deleteValues,
            'delete_values_type' => gettype($deleteValues),
            'delete_values_normalized' => $deleteValuesNormalized,
            'should_delete' => $shouldDelete
        ]);

        return $shouldDelete;
    }

    /**
     * Основная логика обработки события
     */
    public function processEvent($eventName, $webhookData)
    {
        $entityType = $this->bitrixAPI->getEntityTypeFromEvent($eventName);
        $entityId = $webhookData['data']['FIELDS']['ID'] ?? $webhookData['data']['ID'] ?? null;

        if (str_contains($eventName, 'DYNAMICITEM')) {
            $this->logger->info('=== PROCESS EVENT DEBUG ===', [
                'event' => $eventName,
                'entity_type_result' => $entityType,
                'entity_id' => $entityId,
                'webhook_data_keys' => array_keys($webhookData),
                'data_keys' => isset($webhookData['data']) ? array_keys($webhookData['data']) : [],
                'fields_keys' => isset($webhookData['data']['FIELDS']) ? array_keys($webhookData['data']['FIELDS']) : []
            ]);
        }

        if (!$entityType || !$entityId) {
            $this->logger->error('Cannot determine entity type or ID', [
                'event' => $eventName,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return false;
        }

        $this->logger->debug('Processing event', [
            'event' => $eventName,
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);

        $entityData = $this->bitrixAPI->getEntityData($entityType, $entityId);

        // Проверяем, является ли ошибка "Not found"
        if (is_array($entityData) && isset($entityData['error']) && $entityData['error'] === 'NOT_FOUND') {
            $this->logger->info('Entity not found in Bitrix24, deleting from local storage', [
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            // Удаляем элемент из локального хранилища
            $deleted = false;
            switch ($entityType) {
                case 'contact':
                    $deleted = $this->localStorage->deleteContactData($entityId);
                    break;
                case 'company':
                    $deleted = $this->localStorage->deleteCompany($entityId);
                    break;
                case 'smart_process':
                    $deleted = $this->localStorage->deleteProject($entityId);
                    break;
                default:
                    $this->logger->warning('Unknown entity type for deletion', [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId
                    ]);
            }

            if ($deleted) {
                $this->logger->info('Entity successfully deleted from local storage', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]);
                return true; // Считаем успешным, так как элемент удален
            } else {
                $this->logger->warning('Failed to delete entity from local storage (may not exist)', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId
                ]);
                return true; // Считаем успешным, элемент мог не существовать
            }
        }

        if (!$entityData || !isset($entityData['result'])) {
            $this->logger->error('Failed to get entity data from Bitrix24', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'response_type' => gettype($entityData),
                'has_error_key' => is_array($entityData) && isset($entityData['error'])
            ]);
            return false;
        }

        if ($entityType === 'smart_process') {
            $this->logger->debug('Processing smart process entity data structure', [
                'original_structure' => array_keys($entityData),
                'has_result_item' => isset($entityData['result']['item']),
                'has_result_array' => isset($entityData['result']) && is_array($entityData['result']),
                'result_keys' => isset($entityData['result']) ? array_keys($entityData['result']) : []
            ]);

            if (isset($entityData['result']['item'])) {
                $entityData = $entityData['result']['item'];
                $this->logger->debug('Using result.item for smart process data');
            } elseif (isset($entityData['result']) && is_array($entityData['result'])) {
                $entityData = $entityData['result'];
                $this->logger->debug('Using result array for smart process data');
            } else {
                $this->logger->error('Unexpected smart process data structure', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'data_keys' => array_keys($entityData)
                ]);
                return false;
            }
        } else {
            $entityData = $entityData['result'];
        }

        $action = $this->getActionFromEvent($eventName);

        switch ($action) {
            case 'create':
                return $this->handleCreate($entityType, $entityData);

            case 'update':
                return $this->handleUpdate($entityType, $entityData);

            case 'delete':
                return $this->handleDelete($entityType, $entityData);

            default:
                $this->logger->warning('Unknown action for event', [
                    'event' => $eventName,
                    'action' => $action
                ]);
                return true; // Не считаем неизвестное действие ошибкой
        }
    }

    /**
     * Обработка создания сущности
     */
    private function handleCreate($entityType, $entityData)
    {
        switch ($entityType) {
            case 'contact':
                $contactId = $entityData['ID'];

                $existingContact = $this->localStorage->getContact($contactId);
                if ($existingContact) {
                    $this->logger->info('LK already exists for contact, skipping creation', [
                        'contact_id' => $contactId,
                        'lk_id' => $existingContact['id']
                    ]);
                    return true; // ЛК уже существует, не создаем повторно
                }

                if ($this->isValidLKClientValue('contact', $entityData)) {
                    $this->logger->info('Creating LK for new contact with valid LK client field', [
                        'contact_id' => $contactId,
                        'lk_client_field' => $this->config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                        'lk_client_value' => $entityData[$this->config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
                    ]);
                    $result = $this->localStorage->createLK($entityData);
                    if ($result) {
                        $this->syncAllRelatedEntitiesForContact($contactId);

                        $managerField = $this->config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                        $assignedById = $entityData[$managerField] ?? null;
                        $this->syncManagerForContact($contactId, $assignedById);
                    }
                    return $result;
                } else {
                    $this->logger->info('Skipping LK creation for new contact - invalid LK client field value', ['contact_id' => $contactId]);
                }
                break;

            case 'company':
                $contactId = $this->extractContactId($entityData['CONTACT_ID'] ?? null);

                if (empty($contactId) || !$this->hasContactInLocalStorage($contactId)) {
                    $this->logger->info('Skipping company creation - no contact link or contact not found in local storage', [
                        'company_id' => $entityData['ID'],
                        'contact_id' => $contactId
                    ]);
                    return true;
                }

                $this->logger->info('New company created', [
                    'company_id' => $entityData['ID'],
                    'contact_id' => $contactId
                ]);

                // Получаем ИНН компании из реквизитов для новой компании
                $companyInn = $this->bitrixAPI->getCompanyINN($entityData['ID']);
                if ($companyInn !== null) {
                    $this->logger->debug('Company INN obtained from requisites for new company', [
                        'company_id' => $entityData['ID'],
                        'inn' => $companyInn
                    ]);
                }

                $this->localStorage->createCompany($entityData, $companyInn);
                break;

            case 'smart_process':
                $projectId = $entityData['id'] ?? $entityData['ID'] ?? null;
                $this->logger->info('New smart process created', ['process_id' => $projectId]);

                $mapping = $this->config['field_mapping']['smart_process'];
                $mappedProjectData = $this->mapProjectData($entityData, $mapping);
                $clientId = $mappedProjectData['client_id'];

                if (empty($clientId) || !$this->hasContactInLocalStorage($clientId)) {
                    $this->logger->info('Skipping project creation - no client link or client not found in local storage', [
                        'project_id' => $projectId,
                        'client_id' => $clientId
                    ]);
                    return true;
                }

                $this->localStorage->addProject($mappedProjectData);
                break;
        }

        return true;
    }

    /**
     * Обработка обновления сущности
     */
    private function handleUpdate($entityType, $entityData)
    {
        switch ($entityType) {
            case 'contact':
                return $this->handleContactUpdate($entityData);

            case 'company':
                return $this->handleCompanyUpdate($entityData);

            case 'smart_process':
                return $this->handleSmartProcessUpdate($entityData);
        }

        return true;
    }

    /**
     * Обработка обновления контакта
     */
    private function handleContactUpdate($contactData)
    {
        $contactId = $contactData['ID'];

        // contactData уже содержит полные данные из Bitrix24 API (из processEventWithRetry)
        $this->logger->info('Processing contact update with full data from Bitrix24', [
            'contact_id' => $contactId,
            'name' => $contactData['NAME'] ?? 'N/A',
            'email_count' => count($contactData['EMAIL'] ?? []),
            'phone_count' => count($contactData['PHONE'] ?? [])
        ]);

        if ($this->shouldDeleteContactData('contact', $contactData)) {
            $this->logger->info('Deleting contact data due to LK field value', [
                'contact_id' => $contactId,
                'lk_field_value' => $contactData[$this->config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
            ]);

            $deleteResult = $this->localStorage->deleteContactData($contactId);

            if ($deleteResult) {
                $this->logger->info('Contact data deleted successfully', ['contact_id' => $contactId]);
            } else {
                $this->logger->error('Failed to delete contact data', ['contact_id' => $contactId]);
            }

            return $deleteResult;
        }

        $existingContact = $this->localStorage->getContact($contactId);

        if ($existingContact) {
            if ($this->isValidLKClientValue('contact', $contactData)) {
                $this->logger->info('Updating existing contact with full data from Bitrix24 - valid LK field', [
                    'contact_id' => $contactId,
                    'lk_id' => $existingContact['id']
                ]);
                $result = $this->localStorage->syncContactByBitrixId($contactId, $contactData);
                if ($result) {
                    // Синхронизируем менеджера при обновлении существующего контакта
                    $managerField = $this->config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                    $assignedById = $contactData[$managerField] ?? null;
                    $this->logger->debug('Extracting manager ID for existing contact update', [
                        'contact_id' => $contactId,
                        'manager_field' => $managerField,
                        'assigned_by_id' => $assignedById,
                        'has_field' => isset($contactData[$managerField])
                    ]);
                    $this->syncManagerForContact($contactId, $assignedById);
                }

                return $result;
            } else {
                $this->logger->info('Skipping contact update - invalid LK client field value', [
                    'contact_id' => $contactId,
                    'lk_id' => $existingContact['id']
                ]);
                return true;
            }
        } else {
            if ($this->isValidLKClientValue('contact', $contactData)) {
                $this->logger->info('Creating new LK with full data from Bitrix24 - valid LK client field value', [
                    'contact_id' => $contactId,
                    'lk_client_field' => $this->config['field_mapping']['contact']['lk_client_field'] ?? 'N/A',
                    'lk_client_value' => $contactData[$this->config['field_mapping']['contact']['lk_client_field'] ?? ''] ?? 'N/A'
                ]);
                $result = $this->localStorage->createLK($contactData);
                if ($result) {
                    $this->syncAllRelatedEntitiesForContact($contactId);

                    $managerField = $this->config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
                    $assignedById = $contactData[$managerField] ?? null;
                    $this->logger->debug('Extracting manager ID for contact', [
                        'contact_id' => $contactId,
                        'manager_field' => $managerField,
                        'assigned_by_id' => $assignedById,
                        'has_field' => isset($contactData[$managerField])
                    ]);
                    $this->syncManagerForContact($contactId, $assignedById);
                }
                return $result;
            } else {
                $this->logger->info('Skipping LK creation for contact update - invalid LK client field value', ['contact_id' => $contactId]);
                return true; // Не считаем это ошибкой, просто пропускаем создание
            }
        }
    }

    /**
     * Обработка обновления компании
     */
    private function handleCompanyUpdate($companyData)
    {
        $companyId = $companyData['ID'];

        $this->logger->info('Fetching full company data from Bitrix24 API via crm.company.get', ['company_id' => $companyId]);

        try {
            $fullCompanyData = $this->bitrixAPI->getEntityData('company', $companyId);

            if (!$fullCompanyData) {
                $this->logger->warning('Failed to fetch company data from Bitrix24 API', ['company_id' => $companyId]);
                return false;
            }

            // Извлекаем данные из result, так как API возвращает ['result' => [...], 'time' => [...]]
            if (!isset($fullCompanyData['result'])) {
                $this->logger->error('Company data structure is invalid - missing result key', [
                    'company_id' => $companyId,
                    'data_keys' => array_keys($fullCompanyData)
                ]);
                return false;
            }

            $companyData = $fullCompanyData['result'];

            $this->logger->debug('Company data keys from API', [
                'company_id' => $companyId,
                'all_keys' => array_keys($companyData),
                'has_contact_id' => isset($companyData['CONTACT_ID']),
                'contact_id_value' => $companyData['CONTACT_ID'] ?? 'NOT_SET'
            ]);

            $this->logger->info('Successfully fetched company data from Bitrix24', [
                'company_id' => $companyId,
                'title' => $companyData['TITLE'] ?? 'N/A'
            ]);

            $rawContactId = $companyData['CONTACT_ID'] ?? null;
            $this->logger->debug('Raw CONTACT_ID from company data', [
                'company_id' => $companyId,
                'raw_contact_id' => $rawContactId,
                'raw_contact_id_type' => gettype($rawContactId),
                'is_array' => is_array($rawContactId),
                'is_empty' => empty($rawContactId),
                'contact_id_keys' => is_array($rawContactId) ? array_keys($rawContactId) : 'not_array'
            ]);

            $contactId = $this->extractContactId($rawContactId);

            $this->logger->debug('Extracted contact ID', [
                'company_id' => $companyId,
                'raw_contact_id' => $rawContactId,
                'extracted_contact_id' => $contactId,
                'has_contact_in_storage' => !empty($contactId) ? $this->hasContactInLocalStorage($contactId) : false
            ]);

            if (empty($contactId)) {
                $this->logger->info('CONTACT_ID is empty, trying to get company contacts via crm.company.contact.items.get', [
                    'company_id' => $companyId
                ]);

                $companyContacts = $this->bitrixAPI->getCompanyContacts($companyId);

                // getCompanyContacts может вернуть false (ошибка) или массив (пустой или с контактами)
                if ($companyContacts !== false && is_array($companyContacts) && !empty($companyContacts)) {
                    $this->logger->info('Found company contacts via API', [
                        'company_id' => $companyId,
                        'contacts_count' => count($companyContacts),
                        'contact_ids' => array_column($companyContacts, 'CONTACT_ID')
                    ]);

                    // Ищем первый контакт, который есть в локальном хранилище
                    foreach ($companyContacts as $contactItem) {
                        $linkedContactId = $this->extractContactId($contactItem['CONTACT_ID'] ?? null);
                        if (!empty($linkedContactId) && $this->hasContactInLocalStorage($linkedContactId)) {
                            $contactId = $linkedContactId;
                            $this->logger->info('Found valid contact in local storage from company contacts', [
                                'company_id' => $companyId,
                                'contact_id' => $contactId
                            ]);
                            break;
                        }
                    }

                    if (empty($contactId)) {
                        $this->logger->info('No contacts from company contacts list found in local storage', [
                            'company_id' => $companyId,
                            'contacts_checked' => count($companyContacts)
                        ]);
                    }
                } else {
                    $this->logger->debug('No company contacts found via API', [
                        'company_id' => $companyId
                    ]);
                }
            }

            if (empty($contactId) || !$this->hasContactInLocalStorage($contactId)) {
                $this->logger->info('Skipping company sync - no contact link or contact not found in local storage', [
                    'company_id' => $companyId,
                    'company_title' => $companyData['TITLE'] ?? 'N/A',
                    'contact_id' => $contactId,
                    'raw_contact_id' => $rawContactId
                ]);
                return true; // Не считаем это ошибкой, просто пропускаем синхронизацию
            }

            $this->logger->info('Syncing company by Bitrix ID', [
                'company_id' => $companyId,
                'company_title' => $companyData['TITLE'] ?? 'N/A',
                'contact_id' => $contactId
            ]);

            $companyData['CONTACT_ID'] = $contactId;

            // Получаем ИНН компании из реквизитов
            $companyInn = $this->bitrixAPI->getCompanyINN($companyId);
            if ($companyInn !== null) {
                $this->logger->debug('Company INN obtained from requisites', [
                    'company_id' => $companyId,
                    'inn' => $companyInn
                ]);
            } else {
                $this->logger->debug('No INN found in company requisites', [
                    'company_id' => $companyId
                ]);
            }

            // Синхронизируем компанию через LocalStorage
            $result = $this->localStorage->syncCompanyByBitrixId($companyId, $companyData, $companyInn);
            if (!$result) {
                $this->logger->error('Failed to sync company', [
                    'company_id' => $companyId
                ]);
                return false;
            }

            return true;

        } catch (Exception $e) {
            $this->logger->error('Error fetching company data from Bitrix24 API', [
                'company_id' => $companyId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Обработка обновления смарт-процесса
     */
    private function handleSmartProcessUpdate($processData)
    {
        $mapping = $this->config['field_mapping']['smart_process'];
        $mappedProjectData = $this->mapProjectData($processData, $mapping);

        $projectId = $mappedProjectData['bitrix_id'];
        $clientId = $mappedProjectData['client_id'];

        $this->logger->info('Smart process updated', [
            'process_id' => $projectId ?? 'NULL_ID',
            'entity_type' => $processData['ENTITY_TYPE'] ?? 'unknown'
        ]);

        if (empty($clientId) || !$this->hasContactInLocalStorage($clientId)) {
            $this->logger->info('Skipping project sync - no client link or client not found in local storage', [
                'project_id' => $projectId,
                'client_id' => $clientId,
                'organization_name' => $mappedProjectData['organization_name']
            ]);
            return true;
        }

        if (empty($projectId)) {
            $this->logger->error('Cannot sync project - no valid ID found', ['process_data' => $processData]);
            return false;
        }

        $this->logger->debug('Syncing project to local storage', [
            'project_id' => $projectId,
            'client_id' => $clientId,
            'organization_name' => $mappedProjectData['organization_name']
        ]);

        return $this->localStorage->syncProjectByBitrixId($projectId, $mappedProjectData);
    }

    /**
     * Обработка удаления сущности
     */
    private function handleDelete($entityType, $entityData)
    {
        switch ($entityType) {
            case 'contact':
                // Обработка удаления контакта
                $this->logger->info('Contact deleted', ['contact_id' => $entityData['ID'] ?? 'unknown']);
                // Возможно, требуется деактивация ЛК
                break;

            case 'company':
                $this->logger->info('Company deleted', ['company_id' => $entityData['ID'] ?? 'unknown']);
                break;

            case 'smart_process':
                $projectId = $entityData['id'] ?? $entityData['ID'] ?? null;
                $this->logger->info('Smart process deleted', ['project_id' => $projectId ?? 'unknown']);
                if ($projectId) {
                    $this->localStorage->deleteProject($projectId);
                }
                break;
        }

        return true;
    }

    /**
     * Определение действия из названия события
     */
    private function getActionFromEvent($eventName)
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
     * Синхронизация связанных компаний для контакта
     */
    private function syncAllRelatedEntitiesForContact($contactId)
    {
        $this->logger->info('Checking for related companies for contact', ['contact_id' => $contactId]);

        try {
            // Ищем компании, где текущий контакт указан как CONTACT_ID
            $companies = $this->bitrixAPI->getEntityList('company', ['CONTACT_ID' => $contactId]);

            if ($companies && isset($companies['result'])) {
                $companyList = $companies['result'];
                $this->logger->info('Found related companies for contact', [
                    'contact_id' => $contactId,
                    'companies_count' => count($companyList)
                ]);

                foreach ($companyList as $company) {
                    $companyId = $company['ID'];
                    $this->logger->info('Processing related company', [
                        'company_id' => $companyId,
                        'company_title' => $company['TITLE'] ?? 'N/A',
                        'contact_id' => $contactId
                    ]);

                    // Получаем полные данные компании
                    $fullCompanyData = $this->bitrixAPI->getEntityData('company', $companyId);
                    if ($fullCompanyData && isset($fullCompanyData['result'])) {
                        $companyData = $fullCompanyData['result'];
                        $companyContactId = $this->extractContactId($companyData['CONTACT_ID'] ?? null);

                        // Проверяем, что компания действительно связана с контактом
                        $isRelated = false;
                        if (!empty($companyContactId) && $companyContactId === (string)$contactId) {
                            $isRelated = true;
                        } elseif (empty($companyContactId)) {
                            // Если CONTACT_ID пустой, проверяем через множественную связь
                            $companyContacts = $this->bitrixAPI->getCompanyContacts($companyId);
                            if ($companyContacts !== false) {
                                foreach ($companyContacts as $relatedContact) {
                                    $relatedContactId = $this->extractContactId($relatedContact['CONTACT_ID'] ?? null);
                                    if (!empty($relatedContactId) && (string)$relatedContactId === (string)$contactId) {
                                        $isRelated = true;
                                        break;
                                    }
                                }
                            }
                        }

                        if (!$isRelated) {
                            $this->logger->warning('Skipping company sync - not related to contact', [
                                'company_id' => $companyId,
                                'company_title' => $companyData['TITLE'] ?? 'N/A',
                                'contact_id' => $contactId,
                                'company_contact_id' => $companyContactId
                            ]);
                            continue;
                        }

                        $this->logger->info('Syncing related company to contact LK', [
                            'company_id' => $companyId,
                            'company_title' => $companyData['TITLE'] ?? 'N/A',
                            'contact_id' => $contactId,
                            'company_contact_id' => $companyContactId
                        ]);

                        $companyData['CONTACT_ID'] = $contactId;

                        // Получаем ИНН компании из реквизитов для связанной компании
                        $companyInn = $this->bitrixAPI->getCompanyINN($companyId);
                        if ($companyInn !== null) {
                            $this->logger->debug('Company INN obtained from requisites for related company', [
                                'company_id' => $companyId,
                                'inn' => $companyInn
                            ]);
                        }

                        $syncResult = $this->localStorage->syncCompanyByBitrixId($companyId, $companyData, $companyInn);
                            if (!$syncResult) {
                                $this->logger->error('Failed to sync related company', [
                                    'company_id' => $companyId,
                                    'contact_id' => $contactId
                            ]);
                        }
                    } else {
                        $this->logger->error('Failed to get company data from Bitrix24 API', [
                            'company_id' => $companyId,
                            'contact_id' => $contactId
                        ]);
                    }
                }
            } else {
                $this->logger->debug('No related companies found for contact', ['contact_id' => $contactId]);
            }

            // Ищем связанные проекты (смарт-процессы)
            $this->logger->info('Checking for related projects for contact', ['contact_id' => $contactId]);

            try {
                $smartProcessId = $this->config['bitrix24']['smart_process_id'] ?? null;
                if ($smartProcessId) {
                    // Ищем смарт-процессы, где текущий контакт указан как клиент
                    // Используем поле contactId из маппинга для фильтрации
                    $mapping = $this->config['field_mapping']['smart_process'];
                    $clientFieldName = $mapping['client_id'] ?? 'contactId';

                    $this->logger->debug('Searching projects by contact', [
                        'contact_id' => $contactId,
                        'client_field_name' => $clientFieldName,
                        'smart_process_id' => $smartProcessId
                    ]);

                    $projects = $this->bitrixAPI->getEntityList('smart_process', [$clientFieldName => $contactId]);

                    $this->logger->debug('Projects API response', [
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
                        $this->logger->info('Found related projects for contact', [
                            'contact_id' => $contactId,
                            'projects_count' => is_array($projectList) ? count($projectList) : 0,
                            'project_list_type' => gettype($projectList)
                        ]);

                        foreach ($projectList as $project) {
                            $projectId = $project['ID'] ?? $project['id'] ?? null;

                            // Пропускаем проекты без ID
                            if (empty($projectId)) {
                                $this->logger->warning('Skipping project without ID in list', [
                                    'contact_id' => $contactId,
                                    'project_data' => $project
                                ]);
                                continue;
                            }

                            $this->logger->info('Processing related project', [
                                'project_id' => $projectId,
                                'project_title' => $project['TITLE'] ?? 'N/A',
                                'contact_id' => $contactId
                            ]);

                            // Получаем полные данные проекта
                            $fullProjectData = $this->bitrixAPI->getEntityData('smart_process', $projectId);
                            if (!$fullProjectData) {
                                $this->logger->error('Failed to get project data from Bitrix24 API', [
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
                                $this->logger->error('Unexpected project data structure from Bitrix24 API', [
                                    'project_id' => $projectId,
                                    'contact_id' => $contactId,
                                    'data_keys' => array_keys($fullProjectData)
                                ]);
                                continue;
                            }

                            // Проверяем, что contactId все еще указывает на наш контакт
                            $mapping = $this->config['field_mapping']['smart_process'];
                            $projectContactId = $this->extractContactId($projectData[$mapping['client_id']] ?? null);

                            if ($projectContactId === $contactId) {
                                // Маппируем данные проекта используя функцию mapProjectData
                                $mappedProjectData = $this->mapProjectData($projectData, $mapping);

                                $this->logger->info('Syncing related project to contact LK', [
                                    'project_id' => $projectId,
                                    'project_title' => $projectData['title'] ?? $projectData['TITLE'] ?? 'N/A',
                                    'contact_id' => $contactId,
                                    'project_contact_id' => $projectContactId
                                ]);

                                // Синхронизировать проект через LocalStorage с маппированными данными
                                $syncResult = $this->localStorage->syncProjectByBitrixId($projectId, $mappedProjectData);
                                if (!$syncResult) {
                                    $this->logger->error('Failed to sync related project', [
                                        'project_id' => $projectId,
                                        'contact_id' => $contactId
                                    ]);
                                } else {
                                    $this->logger->info('Successfully synced related project', [
                                        'project_id' => $projectId,
                                        'contact_id' => $contactId
                                    ]);
                                }
                            } else {
                                $this->logger->warning('Project client does not match contact, skipping', [
                                    'project_id' => $projectId,
                                    'expected_contact_id' => $contactId,
                                    'actual_contact_id' => $projectContactId
                                ]);
                            }
                        }
                    } else {
                        $this->logger->debug('No related projects found for contact', ['contact_id' => $contactId]);
                    }
                } else {
                    $this->logger->warning('Smart process ID not configured, skipping project sync', ['contact_id' => $contactId]);
                }
            } catch (Exception $e) {
                $this->logger->error('Error syncing related projects for contact', [
                    'contact_id' => $contactId,
                    'error' => $e->getMessage()
                ]);
            }

        } catch (Exception $e) {
            $this->logger->error('Error syncing related entities for contact', [
                'contact_id' => $contactId,
                'error' => $e->getMessage()
            ]);
        }
    }
}