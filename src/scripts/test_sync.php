<?php

/**
 * Скрипт для тестовой синхронизации одного ЛК с его связанными сущностями
 * Синхронизирует контакт, его компании, проекты и менеджера
 * 
 * Использование:
 * php test_sync.php [contact_id]
 * 
 * Примеры:
 * php test_sync.php        # Синхронизирует первый найденный контакт
 * php test_sync.php 2      # Синхронизирует контакт с ID=2
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

/**
 * Извлечение ID контакта из значения (может быть строкой или массивом)
 */
function extractContactId($rawValue)
{
    if (is_array($rawValue)) {
        return !empty($rawValue) ? (string)$rawValue[0] : null;
    }
    return !empty($rawValue) ? (string)$rawValue : null;
}

/**
 * Маппинг данных проекта из Bitrix24 в локальный формат
 */
function mapProjectData($projectData, $mapping, $logger)
{
    $projectId = $projectData['id'] ?? $projectData['ID'] ?? null;
    $clientId = extractContactId($projectData[$mapping['client_id']] ?? null);
    
    // Обработка списочного поля "Тип запросов"
    $requestTypeRaw = $projectData[$mapping['request_type']] ?? null;
    $requestType = '';
    if (!empty($requestTypeRaw)) {
        if (is_array($requestTypeRaw)) {
            $requestType = $requestTypeRaw[0] ?? $requestTypeRaw['ID'] ?? '';
        } else {
            $requestType = (string)$requestTypeRaw;
        }
    }
    
    // Обработка поля файла "Перечень оборудования" (множественное поле)
    $equipmentListRaw = $projectData[$mapping['equipment_list']] ?? null;
    $equipmentList = [];
    if (!empty($equipmentListRaw)) {
        if (is_array($equipmentListRaw)) {
            foreach ($equipmentListRaw as $file) {
                if (is_array($file)) {
                    $fileInfo = [
                        'id' => $file['id'] ?? $file['ID'] ?? null,
                        'name' => $file['name'] ?? $file['NAME'] ?? null,
                        'url' => $file['downloadUrl'] ?? $file['DOWNLOAD_URL'] ?? null,
                        'size' => $file['size'] ?? $file['SIZE'] ?? null
                    ];
                    if (!empty($fileInfo['id'])) {
                        $equipmentList[] = $fileInfo;
                    }
                } else {
                    if (!empty($file)) {
                        $equipmentList[] = ['id' => $file];
                    }
                }
            }
        } else {
            $equipmentList = [['id' => $equipmentListRaw]];
        }
    }
    
    // Обработка чекбокса "Маркетинговая скидка"
    $marketingDiscountRaw = $projectData[$mapping['marketing_discount']] ?? null;
    $marketingDiscount = false;
    if (!empty($marketingDiscountRaw)) {
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
    
    return [
        'bitrix_id' => $projectId,
        'organization_name' => $projectData[$mapping['organization_name']] ?? '',
        'object_name' => $projectData[$mapping['object_name']] ?? '',
        'system_types' => $systemTypes,
        'location' => $location,
        'implementation_date' => $projectData[$mapping['implementation_date']] ?? null,
        'request_type' => $requestType,
        'equipment_list' => $equipmentList,
        'competitors' => $projectData[$mapping['competitors']] ?? '',
        'marketing_discount' => $marketingDiscount,
        'technical_description' => $technicalDescription,
        'status' => $projectData[$mapping['status']] ?? 'NEW',
        'client_id' => $clientId,
        'manager_id' => $projectData['assignedById'] ?? $projectData['ASSIGNED_BY_ID'] ?? null
    ];
}

/**
 * Синхронизация связанных компаний, проектов и сделок для контакта
 */
function syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger, $contactCompanyIdFromAPI = null)
{
    $results = [
        'companies' => ['count' => 0, 'success' => 0, 'failed' => 0],
        'projects' => ['count' => 0, 'success' => 0, 'failed' => 0]
    ];

    $logger->info('Checking for related companies for contact', ['contact_id' => $contactId]);
    
    try {
        // Получаем COMPANY_ID из локального хранилища и из API
        $contactFromStorage = $localStorage->getContact($contactId);
        $companyIdFromStorage = $contactFromStorage['company'] ?? null;
        
        // Используем COMPANY_ID из API, если он есть, иначе из локального хранилища
        $companyIdToCheck = $contactCompanyIdFromAPI ?? $companyIdFromStorage;
        
        // Ищем компании через фильтр CONTACT_ID
        $companies = $bitrixAPI->getEntityList('company', ['CONTACT_ID' => $contactId]);

        if ($companies && isset($companies['result'])) {
            $companyList = $companies['result'];
            $logger->info('Found related companies for contact', [
                'contact_id' => $contactId,
                'companies_count' => count($companyList)
            ]);

            $results['companies']['count'] = 0;
            foreach ($companyList as $company) {
                $companyId = $company['ID'];
                $fullCompanyData = $bitrixAPI->getEntityData('company', $companyId);
                if ($fullCompanyData && isset($fullCompanyData['result'])) {
                    $companyData = $fullCompanyData['result'];

                    // Проверяем фактическую привязку к контакту
                    // Связь может быть двунаправленной:
                    // 1. Через CONTACT_ID в компании (компания связана с контактом)
                    // 2. Через COMPANY_ID в контакте (контакт связан с компанией)
                    $companyContactId = extractContactId($companyData['CONTACT_ID'] ?? null);
                    $isLinkedViaContactId = !empty($companyContactId) && (string)$companyContactId === (string)$contactId;
                    $isLinkedViaCompanyId = !empty($companyIdToCheck) && (string)$companyIdToCheck === (string)$companyId;
                    
                    // Пропускаем компанию только если она не связана ни одним из способов
                    if (!$isLinkedViaContactId && !$isLinkedViaCompanyId) {
                        $logger->info('Skipping company - contact mismatch or empty', [
                            'company_id' => $companyId,
                            'expected_contact_id' => $contactId,
                            'actual_contact_id' => $companyContactId,
                            'link_via_contact_id' => $isLinkedViaContactId,
                            'link_via_company_id' => $isLinkedViaCompanyId
                        ]);
                        continue;
                    }

                    $results['companies']['count']++;
                    $syncResult = $localStorage->syncCompanyByBitrixId($companyId, $companyData);
                    
                    if ($syncResult) {
                        $results['companies']['success']++;
                    } else {
                        $results['companies']['failed']++;
                    }
                }
            }
        }
        
        // Если COMPANY_ID есть в контакте, но не найдена через CONTACT_ID фильтр,
        // попробуем получить компанию напрямую по ID
        if (!empty($companyIdToCheck) && $results['companies']['count'] === 0) {
            $fullCompanyData = $bitrixAPI->getEntityData('company', $companyIdToCheck);
            if ($fullCompanyData && isset($fullCompanyData['result'])) {
                $companyData = $fullCompanyData['result'];
                
                // Синхронизируем компанию, даже если связь через CONTACT_ID не найдена
                // (связь может быть через COMPANY_ID в контакте)
                $syncResult = $localStorage->syncCompanyByBitrixId($companyIdToCheck, $companyData);
                
                if ($syncResult) {
                    $results['companies']['count']++;
                    $results['companies']['success']++;
                } else {
                    $results['companies']['failed']++;
                }
            }
        }
    } catch (Exception $e) {
        $logger->error('Error syncing related companies', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
    }

    $logger->info('Checking for related projects for contact', ['contact_id' => $contactId]);
    try {
        $smartProcessId = $config['bitrix24']['smart_process_id'] ?? null;
        if ($smartProcessId) {
            $mapping = $config['field_mapping']['smart_process'];
            $clientFieldName = $mapping['client_id'] ?? 'contactId';
            
            $projects = $bitrixAPI->getEntityList('smart_process', [$clientFieldName => $contactId]);

            if ($projects && isset($projects['result'])) {
                // crm.item.list для смарт-процессов возвращает элементы в result.items
                $projectList = isset($projects['result']['items'])
                    ? $projects['result']['items']
                    : $projects['result'];
                $results['projects']['count'] = is_array($projectList) ? count($projectList) : 0;

                foreach ($projectList as $project) {
                    $projectId = $project['ID'] ?? $project['id'] ?? null;
                    if (empty($projectId)) {
                        continue;
                    }

                    $fullProjectData = $bitrixAPI->getEntityData('smart_process', $projectId);
                    if (!$fullProjectData) {
                        $results['projects']['failed']++;
                        continue;
                    }

                    $projectData = null;
                    if (isset($fullProjectData['result']['item'])) {
                        $projectData = $fullProjectData['result']['item'];
                    } elseif (isset($fullProjectData['result']) && is_array($fullProjectData['result'])) {
                        $projectData = $fullProjectData['result'];
                    }

                    if ($projectData) {
                        $projectContactId = extractContactId($projectData[$mapping['client_id']] ?? null);
                        if ((string)$projectContactId === (string)$contactId) {
                            $mappedProjectData = mapProjectData($projectData, $mapping, $logger);
                            $syncResult = $localStorage->syncProjectByBitrixId($projectId, $mappedProjectData);
                            if ($syncResult) {
                                $results['projects']['success']++;
                            } else {
                                $results['projects']['failed']++;
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $logger->error('Error syncing related projects', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
    }

    return $results;
}

$contactIdFilter = $argv[1] ?? null;

echo "=== ТЕСТОВАЯ СИНХРОНИЗАЦИЯ ОДНОГО ЛК ===\n\n";

$results = [
    'contact' => ['success' => false, 'error' => null],
    'manager' => ['success' => false, 'error' => null],
    'related' => null,
    'errors' => []
];

$contactsFile = $config['local_storage']['contacts_file'];
if (!file_exists($contactsFile)) {
    echo "✗ ОШИБКА: Файл контактов не найден: {$contactsFile}\n";
    exit(1);
}

$contactsData = json_decode(file_get_contents($contactsFile), true);

if (empty($contactsData)) {
    echo "✗ ОШИБКА: Нет контактов для синхронизации\n";
    exit(1);
}

// Выбираем контакт
$selectedContactId = null;
$selectedContactData = null;

if ($contactIdFilter) {
    if (isset($contactsData[$contactIdFilter])) {
        $selectedContactId = $contactIdFilter;
        $selectedContactData = $contactsData[$contactIdFilter];
    } else {
        echo "✗ ОШИБКА: Контакт с ID={$contactIdFilter} не найден в локальном хранилище\n";
        exit(1);
    }
} else {
    $selectedContactId = array_key_first($contactsData);
    $selectedContactData = $contactsData[$selectedContactId];
}

echo "Выбран контакт для синхронизации:\n";
echo "  ID: {$selectedContactId}\n";
if (isset($selectedContactData['name']) && isset($selectedContactData['last_name'])) {
    echo "  Имя: {$selectedContactData['name']} {$selectedContactData['last_name']}\n";
}
echo "\n";

// Синхронизация контакта
echo "--- СИНХРОНИЗАЦИЯ КОНТАКТА ---\n";

try {
    $fullContactData = $bitrixAPI->getEntityData('contact', $selectedContactId);
    
    if (!$fullContactData || !isset($fullContactData['result'])) {
        echo "  ✗ ОШИБКА: Не удалось получить данные из Bitrix24\n";
        $results['contact']['error'] = 'Failed to fetch from Bitrix24';
        exit(1);
    }
    
    $contactDataFromAPI = $fullContactData['result'];
    
    $lkClientField = $config['field_mapping']['contact']['lk_client_field'] ?? '';
    $lkClientValues = $config['field_mapping']['contact']['lk_client_values'] ?? [];
    
    if (!empty($lkClientField)) {
        $lkClientValue = $contactDataFromAPI[$lkClientField] ?? null;
        
        // Нормализуем значения для сравнения (приводим к строкам)
        // Bitrix24 может возвращать значения как строки или числа
        if (!empty($lkClientValue) || $lkClientValue === '0' || $lkClientValue === 0) {
            $lkClientValueNormalized = (string)$lkClientValue;
            $lkClientValuesNormalized = array_map('strval', $lkClientValues);
            $isValid = in_array($lkClientValueNormalized, $lkClientValuesNormalized, true);
        } else {
            $isValid = false;
        }
        
        if (!$isValid) {
            echo "  ⊘ ПРОПУЩЕН: Поле ЛК клиента невалидно или пустое\n";
            echo "  Значение поля: " . ($lkClientValue ?? 'не указано') . " (тип: " . gettype($lkClientValue) . ")\n";
            echo "  Нормализованное значение: " . (isset($lkClientValueNormalized) ? $lkClientValueNormalized : 'не определено') . "\n";
            echo "  Допустимые значения: " . implode(', ', $lkClientValues) . "\n";
            exit(1);
        }
    }
    
    // Сохраняем COMPANY_ID из API для использования в синхронизации компаний
    $contactCompanyIdFromAPI = extractContactId($contactDataFromAPI['COMPANY_ID'] ?? null);
    
    $syncResult = $localStorage->syncContactByBitrixId($selectedContactId, $contactDataFromAPI);
    
    if ($syncResult) {
        echo "  ✓ УСПЕХ: Контакт синхронизирован\n";
        $results['contact']['success'] = true;
    } else {
        echo "  ✗ ОШИБКА: Не удалось синхронизировать контакт\n";
        $results['contact']['error'] = 'Sync failed';
        exit(1);
    }
    
} catch (Exception $e) {
    echo "  ✗ ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
    $results['contact']['error'] = $e->getMessage();
    exit(1);
}

echo "\n";

echo "--- СИНХРОНИЗАЦИЯ МЕНЕДЖЕРА ---\n";

try {
    $managerField = $config['field_mapping']['contact']['manager_id'] ?? 'ASSIGNED_BY_ID';
    $assignedById = $contactDataFromAPI[$managerField] ?? null;
    
    if (!empty($assignedById)) {
        echo "  Менеджер ID: {$assignedById}\n";
        
        $managerData = $bitrixAPI->getEntityData('user', $assignedById);
        if ($managerData && isset($managerData['result'])) {
            $userData = $managerData['result'];
            if (is_array($userData) && isset($userData[0])) {
                $userData = $userData[0];
            }
            
            $localStorage->syncManagerByBitrixId($assignedById, $userData);
            echo "  ✓ УСПЕХ: Менеджер синхронизирован\n";
            $results['manager']['success'] = true;
        } else {
            echo "  ⊘ ПРОПУЩЕН: Не удалось получить данные менеджера\n";
            $results['manager']['error'] = 'Failed to fetch manager data';
        }
    } else {
        echo "  ⊘ ПРОПУЩЕН: Менеджер не указан\n";
    }
} catch (Exception $e) {
    echo "  ✗ ИСКЛЮЧЕНИЕ: " . $e->getMessage() . "\n";
    $results['manager']['error'] = $e->getMessage();
}

echo "\n";

echo "--- СИНХРОНИЗАЦИЯ СВЯЗАННЫХ СУЩНОСТЕЙ ---\n\n";

try {
    $relatedResults = syncAllRelatedEntitiesForContact($selectedContactId, $bitrixAPI, $localStorage, $config, $logger, $contactCompanyIdFromAPI ?? null);
    $results['related'] = $relatedResults;
    
    echo "  Компании: найдено {$relatedResults['companies']['count']}, успешно {$relatedResults['companies']['success']}, ошибок {$relatedResults['companies']['failed']}\n";
    echo "  Проекты: найдено {$relatedResults['projects']['count']}, успешно {$relatedResults['projects']['success']}, ошибок {$relatedResults['projects']['failed']}\n";
    
} catch (Exception $e) {
    echo "  ✗ ОШИБКА: " . $e->getMessage() . "\n";
    $results['errors'][] = "Related entities sync: " . $e->getMessage();
}

echo "\n";

// Итоговая статистика
echo "=== ИТОГИ СИНХРОНИЗАЦИИ ===\n\n";

echo "Контакт:\n";
if ($results['contact']['success']) {
    echo "  ✓ Успешно синхронизирован\n";
} else {
    echo "  ✗ Ошибка: " . ($results['contact']['error'] ?? 'unknown') . "\n";
}
echo "\n";

echo "Менеджер:\n";
if ($results['manager']['success']) {
    echo "  ✓ Успешно синхронизирован\n";
} elseif ($results['manager']['error']) {
    echo "  ⊘ " . $results['manager']['error'] . "\n";
} else {
    echo "  ⊘ Не указан\n";
}
echo "\n";

if ($results['related']) {
    echo "Связанные сущности:\n";
    echo "  Компании: {$results['related']['companies']['success']}/{$results['related']['companies']['count']}\n";
    echo "  Проекты: {$results['related']['projects']['success']}/{$results['related']['projects']['count']}\n";
    echo "\n";
}

if (!empty($results['errors'])) {
    echo "Ошибки:\n";
    foreach ($results['errors'] as $error) {
        echo "  - {$error}\n";
    }
    echo "\n";
}

echo "=== СИНХРОНИЗАЦИЯ ЗАВЕРШЕНА ===\n";
echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";
