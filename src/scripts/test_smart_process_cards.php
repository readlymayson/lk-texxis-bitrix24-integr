<?php

/**
 * Скрипт для проверки создания карточек в смарт-процессах
 * - Изменение данных в ЛК
 * - Удаление пользовательских данных
 * 
 * Для тестирования создания проектов используйте отдельный скрипт:
 * php test_project_creation.php [contact_id] [file_id]
 * 
 * Использование:
 * php test_smart_process_cards.php [contact_id]
 * 
 * Примеры:
 * php test_smart_process_cards.php 3                    # Создание карточек для контакта ID=3
 * 
 * Примечание: 
 * - company_id и manager_id автоматически берутся из базы данных ЛК по contact_id
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

$contactId = $argv[1] ?? null;

echo "=== ТЕСТИРОВАНИЕ СОЗДАНИЯ КАРТОЧЕК В СМАРТ-ПРОЦЕССАХ ===\n\n";

if (empty($contactId)) {
    echo "Введите параметры для тестирования:\n";
    echo "Contact ID (обязательно): ";
    $contactId = trim(fgets(STDIN));
    
    if (empty($contactId)) {
        echo "\nОШИБКА: Не указан contact_id (ID контакта)\n";
        echo "Использование: php test_smart_process_cards.php <contact_id>\n";
        echo "Пример: php test_smart_process_cards.php 3\n";
        echo "\nПримечание: company_id и manager_id автоматически берутся из базы данных ЛК\n";
        exit(1);
    }
    
    echo "\n";
}

echo "Параметры:\n";
echo "  Contact ID: {$contactId}\n";
echo "  Company ID: будет получен из базы ЛК\n";
echo "  Manager ID: будет получен из Bitrix24 API\n\n";

$changeDataProcessId = $config['bitrix24']['smart_process_change_data_id'] ?? '';
$deleteDataProcessId = $config['bitrix24']['smart_process_delete_data_id'] ?? '';

echo "Конфигурация:\n";
echo "  Smart Process Change Data ID: " . ($changeDataProcessId ?: 'НЕ НАСТРОЕН') . "\n";
echo "  Smart Process Delete Data ID: " . ($deleteDataProcessId ?: 'НЕ НАСТРОЕН') . "\n\n";

if (empty($changeDataProcessId) && empty($deleteDataProcessId)) {
    echo "ОШИБКА: ID смарт-процессов не настроены в конфигурации!\n";
    echo "Проверьте файл: src/config/bitrix24.php\n";
    exit(1);
}

if (!empty($changeDataProcessId)) {
    echo "--- ТЕСТ 1: Создание карточки 'Изменение данных в ЛК' ---\n";
    
    // Только contact_id, остальные данные берутся из базы ЛК
    $additionalData = [
        'new_email' => 'test_new_email@example.com',
        'new_phone' => '+7 (999) 123-45-67',
        'change_reason_personal' => 'Тестовая причина изменения личных данных',
    ];
    
    echo "Contact ID: {$contactId}\n";
    echo "Дополнительные данные:\n";
    echo json_encode($additionalData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo "Остальные данные (company_id, manager_id) будут получены из базы ЛК\n\n";
    
    echo "Отправка запроса...\n";
    $result = $bitrixAPI->createChangeDataCard($contactId, $additionalData, $localStorage);
    
    if ($result && isset($result['id'])) {
        echo "✓ УСПЕХ: Карточка создана!\n";
        echo "  Card ID: {$result['id']}\n";
        if (isset($result['title'])) {
            echo "  Title: {$result['title']}\n";
        }
        echo "\n";
    } else {
        echo "✗ ОШИБКА: Не удалось создать карточку\n";
        if (is_array($result)) {
            echo "  Response: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "  Response: " . var_export($result, true) . "\n";
        }
        echo "\n";
    }
} else {
    echo "--- ТЕСТ 1: Пропущен (ID смарт-процесса не настроен) ---\n\n";
}

if (!empty($deleteDataProcessId)) {
    echo "--- ТЕСТ 2: Создание карточки 'Удаление пользовательских данных' ---\n";
    
    // Только contact_id, остальные данные берутся из базы ЛК
    echo "Contact ID: {$contactId}\n";
    echo "Данные (company_id, manager_id) будут получены из базы ЛК\n\n";
    
    echo "Отправка запроса...\n";
    $result = $bitrixAPI->createDeleteDataCard($contactId, $localStorage);
    
    if ($result && isset($result['id'])) {
        echo "✓ УСПЕХ: Карточка создана!\n";
        echo "  Card ID: {$result['id']}\n";
        if (isset($result['title'])) {
            echo "  Title: {$result['title']}\n";
        }
        echo "\n";
    } else {
        echo "✗ ОШИБКА: Не удалось создать карточку\n";
        if (is_array($result)) {
            echo "  Response: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        } else {
            echo "  Response: " . var_export($result, true) . "\n";
        }
        echo "\n";
    }
} else {
    echo "--- ТЕСТ 2: Пропущен (ID смарт-процесса не настроен) ---\n\n";
}

echo "=== ТЕСТИРОВАНИЕ ЗАВЕРШЕНО ===\n";
echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";

