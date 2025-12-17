<?php

/**
 * Скрипт для проверки запуска бизнес-процесса отправки email через Bitrix24 API
 * 
 * Использование:
 * php test_send_email.php [contact_id] [url]
 * 
 * Примеры:
 * php test_send_email.php 3 "https://example.com/link"
 * php test_send_email.php 3                                    # Интерактивный режим
 * 
 * Примечание: 
 * - contact_id - ID контакта в Bitrix24
 * - url - строка URL для передачи в бизнес-процесс (опционально, можно ввести интерактивно)
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
$url = $argv[2] ?? null;

echo "=== ТЕСТИРОВАНИЕ ЗАПУСКА БИЗНЕС-ПРОЦЕССА ОТПРАВКИ EMAIL ===\n\n";

// Получение contact_id
if (empty($contactId)) {
    echo "Введите параметры для тестирования:\n";
    echo "Contact ID (обязательно): ";
    $contactId = trim(fgets(STDIN));
    
    if (empty($contactId)) {
        echo "\nОШИБКА: Не указан contact_id (ID контакта)\n";
        echo "Использование: php test_send_email.php <contact_id> [url]\n";
        echo "Пример: php test_send_email.php 3 \"https://example.com/link\"\n";
        exit(1);
    }
    
    echo "\n";
}

// Получение URL
if (empty($url)) {
    echo "URL (Enter для значения по умолчанию): ";
    $url = trim(fgets(STDIN));
    if (empty($url)) {
        $url = "https://example.com/test-link-" . date('YmdHis');
    }
    echo "\n";
}

echo "Параметры запуска бизнес-процесса:\n";
echo "  Contact ID: {$contactId}\n";
echo "  URL: {$url}\n\n";

// Проверка наличия контакта
echo "Проверка контакта...\n";
$bitrixContact = $bitrixAPI->getEntityData('contact', $contactId);
if ($bitrixContact && isset($bitrixContact['result'])) {
    echo "  ✓ Контакт найден в Bitrix24\n\n";
} else {
    echo "  ✗ ОШИБКА: Контакт не найден в Bitrix24\n";
    echo "  Response: " . json_encode($bitrixContact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    echo "Бизнес-процесс не может быть запущен.\n";
    exit(1);
}

// Проверка настройки email_business_process_id
$businessProcessId = $config['bitrix24']['email_business_process_id'] ?? 0;
if (empty($businessProcessId)) {
    echo "⚠ ПРЕДУПРЕЖДЕНИЕ: Настройка 'email_business_process_id' не задана в конфигурации.\n";
    echo "Для настройки добавьте в src/config/bitrix24.php:\n";
    echo "  'email_business_process_id' => <ID_бизнес_процесса>,\n\n";
    echo "Бизнес-процесс не может быть запущен.\n";
    exit(1);
} else {
    echo "✓ ID бизнес-процесса: {$businessProcessId}\n\n";
}

// Запуск бизнес-процесса
echo "--- ЗАПУСК БИЗНЕС-ПРОЦЕССА ---\n";
echo "Отправка запроса в Bitrix24...\n";

$result = $bitrixAPI->startEmailBusinessProcess($contactId, $url);

if ($result && isset($result['result'])) {
    $workflowId = $result['result'] ?? null;
    echo "✓ УСПЕХ: Бизнес-процесс запущен!\n";
    echo "  Workflow ID: " . ($workflowId ?: 'не указан') . "\n";
    
    if (isset($result['time'])) {
        echo "  Время выполнения: {$result['time']} сек\n";
    }
    
    echo "\n";
    echo "Проверьте:\n";
    echo "  1. В карточке контакта в Bitrix24 должен быть запущен бизнес-процесс\n";
    echo "  2. Бизнес-процесс должен отправить письмо на email адрес контакта\n";
    echo "  3. Проверьте логи в файле: " . $config['logging']['file'] . "\n";
    echo "\n";
    
    if (is_array($result)) {
        echo "Полный ответ API:\n";
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    }
} else {
    echo "✗ ОШИБКА: Не удалось запустить бизнес-процесс\n";
    
    if (is_array($result)) {
        echo "  Response: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
        
        if (isset($result['error'])) {
            echo "\n  Ошибка API: " . $result['error'] . "\n";
            if (isset($result['error_description'])) {
                echo "  Описание: " . $result['error_description'] . "\n";
            }
        }
    } else {
        echo "  Response: " . var_export($result, true) . "\n";
    }
    
    echo "\n";
    echo "Возможные причины ошибки:\n";
    echo "  1. Неверный ID бизнес-процесса в конфигурации\n";
    echo "  2. Бизнес-процесс не настроен для работы с контактами\n";
    echo "  3. Недостаточно прав у webhook для запуска бизнес-процессов\n";
    echo "  4. Неверный формат данных в запросе\n";
    echo "\n";
    echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";
    exit(1);
}

echo "=== ТЕСТИРОВАНИЕ ЗАВЕРШЕНО ===\n";

