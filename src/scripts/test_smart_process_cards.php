<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для проверки создания карточек в смарт-процессах
 * - Изменение данных в ЛК
 * - Удаление пользовательских данных
 * 
 * Использование:
 * php test_smart_process_cards.php [contact_id] [company_id] [manager_id]
 * 
 * Примеры:
 * php test_smart_process_cards.php 2                    # Только контакт
 * php test_smart_process_cards.php 2 4                  # Контакт и компания
 * php test_smart_process_cards.php 2 4 1               # Контакт, компания и менеджер
 */

// Подключение автозагрузки классов
require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/../config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);

// Получение параметров из командной строки
$contactId = $argv[1] ?? null;
$companyId = $argv[2] ?? null;
$managerId = $argv[3] ?? null;

echo "=== ТЕСТИРОВАНИЕ СОЗДАНИЯ КАРТОЧЕК В СМАРТ-ПРОЦЕССАХ ===\n\n";

// Интерактивный ввод, если параметры не переданы
if (empty($contactId)) {
    echo "Введите параметры для тестирования:\n";
    echo "Contact ID (обязательно): ";
    $contactId = trim(fgets(STDIN));
    
    if (empty($contactId)) {
        echo "\nОШИБКА: Не указан contact_id (ID контакта)\n";
        echo "Использование: php test_smart_process_cards.php <contact_id> [company_id] [manager_id]\n";
        echo "Пример: php test_smart_process_cards.php 2\n";
        exit(1);
    }
    
    echo "Company ID (необязательно, Enter для пропуска): ";
    $companyIdInput = trim(fgets(STDIN));
    if (!empty($companyIdInput)) {
        $companyId = $companyIdInput;
    }
    
    echo "Manager ID (необязательно, Enter для пропуска): ";
    $managerIdInput = trim(fgets(STDIN));
    if (!empty($managerIdInput)) {
        $managerId = $managerIdInput;
    }
    
    echo "\n";
}

echo "Параметры:\n";
echo "  Contact ID: {$contactId}\n";
echo "  Company ID: " . ($companyId ?? 'не указан') . "\n";
echo "  Manager ID: " . ($managerId ?? 'не указан') . "\n\n";

// Проверка конфигурации
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

// ============================================
// ТЕСТ 1: Создание карточки "Изменение данных в ЛК"
// ============================================
if (!empty($changeDataProcessId)) {
    echo "--- ТЕСТ 1: Создание карточки 'Изменение данных в ЛК' ---\n";
    
    $changeData = [
        'contact_id' => $contactId,
    ];
    
    if (!empty($companyId)) {
        $changeData['company_id'] = $companyId;
    }
    
    if (!empty($managerId)) {
        $changeData['manager_id'] = $managerId;
    }
    
    // Тестовые данные для изменения личных данных
    $changeData['new_email'] = 'test_new_email@example.com';
    $changeData['new_phone'] = '+7 (999) 123-45-67';
    $changeData['change_reason_personal'] = 'Тестовая причина изменения личных данных';
    
    // Тестовые данные для изменения данных компании
    if (!empty($companyId)) {
        $changeData['new_company_name'] = 'Новое название компании (тест)';
        $changeData['new_company_website'] = 'https://test-company.example.com';
        $changeData['new_company_inn'] = '1234567890';
        $changeData['new_company_phone'] = '+7 (999) 765-43-21';
        $changeData['change_reason_company'] = 'Тестовая причина изменения данных компании';
    }
    
    echo "Данные для создания карточки:\n";
    echo json_encode($changeData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Отправка запроса...\n";
    $result = $bitrixAPI->createChangeDataCard($changeData);
    
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

// ============================================
// ТЕСТ 2: Создание карточки "Удаление пользовательских данных"
// ============================================
if (!empty($deleteDataProcessId)) {
    echo "--- ТЕСТ 2: Создание карточки 'Удаление пользовательских данных' ---\n";
    
    $deleteData = [
        'contact_id' => $contactId,
    ];
    
    if (!empty($companyId)) {
        $deleteData['company_id'] = $companyId;
    }
    
    if (!empty($managerId)) {
        $deleteData['manager_id'] = $managerId;
    }
    
    echo "Данные для создания карточки:\n";
    echo json_encode($deleteData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Отправка запроса...\n";
    $result = $bitrixAPI->createDeleteDataCard($deleteData);
    
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

