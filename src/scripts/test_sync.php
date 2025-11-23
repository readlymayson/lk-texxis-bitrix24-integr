<?php

/**
 * Скрипт для тестовой синхронизации одного ЛК с его связанными сущностями
 * Синхронизирует контакт, его компании, проекты, сделки и менеджера
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
$localStorage = new LocalStorage($logger);

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
    
    return [
        'bitrix_id' => $projectId,
        'organization_name' => $projectData[$mapping['organization_name']] ?? '',
        'object_name' => $projectData[$mapping['object_name']] ?? '',
        'system_type' => $projectData[$mapping['system_type']] ?? '',
        'location' => $projectData[$mapping['location']] ?? '',
        'implementation_date' => $projectData[$mapping['implementation_date']] ?? null,
        'status' => $projectData[$mapping['status']] ?? 'NEW',
        'client_id' => $clientId,
        'manager_id' => $projectData['assignedById'] ?? $projectData['ASSIGNED_BY_ID'] ?? null
    ];
}

/**
 * Синхронизация связанных компаний, проектов и сделок для контакта
 */
function syncAllRelatedEntitiesForContact($contactId, $bitrixAPI, $localStorage, $config, $logger)
{
    $results = [
        'companies' => ['count' => 0, 'success' => 0, 'failed' => 0],
        'projects' => ['count' => 0, 'success' => 0, 'failed' => 0],
        'deals' => ['count' => 0, 'success' => 0, 'failed' => 0]
    ];

    $logger->info('Checking for related companies for contact', ['contact_id' => $contactId]);
    try {
        $companies = $bitrixAPI->getEntityList('company', [
            'filter' => ['CONTACT_ID' => $contactId]
        ]);

        if ($companies && isset($companies['result'])) {
            $companyList = $companies['result'];
            $results['companies']['count'] = count($companyList);
            $logger->info('Found related companies for contact', [
                'contact_id' => $contactId,
                'companies_count' => count($companyList)
            ]);

            foreach ($companyList as $company) {
                $companyId = $company['ID'];
                $fullCompanyData = $bitrixAPI->getEntityData('company', $companyId);
                if ($fullCompanyData && isset($fullCompanyData['result'])) {
                    $companyData = $fullCompanyData['result'];
                    $companyData['CONTACT_ID'] = $contactId;
                    $syncResult = $localStorage->syncCompanyByBitrixId($companyId, $companyData);
                    if ($syncResult) {
                        $results['companies']['success']++;
                    } else {
                        $results['companies']['failed']++;
                    }
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
            
            $projects = $bitrixAPI->getEntityList('smart_process', [
                'filter' => [$clientFieldName => $contactId]
            ]);

            if ($projects && isset($projects['result'])) {
                $projectList = $projects['result'];
                $results['projects']['count'] = is_array($projectList) ? count($projectList) : 0;

                foreach ($projectList as $project) {
                    $projectId = $project['ID'] ?? null;
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
                        if ($projectContactId === $contactId) {
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

    $logger->info('Checking for related deals for contact', ['contact_id' => $contactId]);
    try {
        $deals = $bitrixAPI->getEntityList('deal', [
            'filter' => ['CONTACT_ID' => $contactId]
        ]);

        if ($deals && isset($deals['result'])) {
            $dealList = $deals['result'];
            $results['deals']['count'] = count($dealList);

            foreach ($dealList as $deal) {
                $dealId = $deal['ID'];
                $fullDealData = $bitrixAPI->getEntityData('deal', $dealId);
                if ($fullDealData && isset($fullDealData['result'])) {
                    $dealData = $fullDealData['result'];
                    $syncResult = $localStorage->syncDealByBitrixId($dealId, $dealData);
                    if ($syncResult) {
                        $results['deals']['success']++;
                    } else {
                        $results['deals']['failed']++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        $logger->error('Error syncing related deals', ['contact_id' => $contactId, 'error' => $e->getMessage()]);
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
        if (empty($lkClientValue) || !in_array($lkClientValue, $lkClientValues, true)) {
            echo "  ⊘ ПРОПУЩЕН: Поле ЛК клиента невалидно или пустое\n";
            echo "  Значение поля: " . ($lkClientValue ?? 'не указано') . "\n";
            echo "  Допустимые значения: " . implode(', ', $lkClientValues) . "\n";
            exit(1);
        }
    }
    
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
    $relatedResults = syncAllRelatedEntitiesForContact($selectedContactId, $bitrixAPI, $localStorage, $config, $logger);
    $results['related'] = $relatedResults;
    
    echo "  Компании: найдено {$relatedResults['companies']['count']}, успешно {$relatedResults['companies']['success']}, ошибок {$relatedResults['companies']['failed']}\n";
    echo "  Проекты: найдено {$relatedResults['projects']['count']}, успешно {$relatedResults['projects']['success']}, ошибок {$relatedResults['projects']['failed']}\n";
    echo "  Сделки: найдено {$relatedResults['deals']['count']}, успешно {$relatedResults['deals']['success']}, ошибок {$relatedResults['deals']['failed']}\n";
    
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
    echo "  Сделки: {$results['related']['deals']['success']}/{$results['related']['deals']['count']}\n";
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
