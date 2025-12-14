<?php

/**
 * Скрипт для проверки отправки почтовых сообщений через Bitrix24 API
 * 
 * Использование:
 * php test_send_email.php [contact_id] [subject] [message]
 * 
 * Примеры:
 * php test_send_email.php 3 "Тестовое письмо" "Это тестовое сообщение"
 * php test_send_email.php 3                                    # Интерактивный режим
 * 
 * Примечание: 
 * - contact_id - ID контакта в Bitrix24
 * - subject - тема письма (опционально, можно ввести интерактивно)
 * - message - текст письма в HTML формате (опционально, можно ввести интерактивно)
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
$subject = $argv[2] ?? null;
$message = $argv[3] ?? null;

echo "=== ТЕСТИРОВАНИЕ ОТПРАВКИ ПОЧТОВЫХ СООБЩЕНИЙ ===\n\n";

// Получение contact_id
if (empty($contactId)) {
    echo "Введите параметры для тестирования:\n";
    echo "Contact ID (обязательно): ";
    $contactId = trim(fgets(STDIN));
    
    if (empty($contactId)) {
        echo "\nОШИБКА: Не указан contact_id (ID контакта)\n";
        echo "Использование: php test_send_email.php <contact_id> [subject] [message]\n";
        echo "Пример: php test_send_email.php 3 \"Тестовое письмо\" \"Это тестовое сообщение\"\n";
        exit(1);
    }
    
    echo "\n";
}

// Получение темы письма
if (empty($subject)) {
    echo "Тема письма (Enter для значения по умолчанию): ";
    $subject = trim(fgets(STDIN));
    if (empty($subject)) {
        $subject = "Тестовое письмо из ЛК - " . date('d.m.Y H:i:s');
    }
    echo "\n";
}

// Получение текста письма
if (empty($message)) {
    echo "Текст письма (Enter для значения по умолчанию): ";
    $message = trim(fgets(STDIN));
    if (empty($message)) {
        $message = "<h2>Тестовое письмо</h2><p>Это тестовое сообщение, отправленное из личного кабинета через Bitrix24 API.</p><p>Дата отправки: " . date('d.m.Y H:i:s') . "</p>";
    }
    echo "\n";
}

echo "Параметры отправки:\n";
echo "  Contact ID: {$contactId}\n";
echo "  Тема: {$subject}\n";
echo "  Сообщение: " . (strlen($message) > 100 ? substr($message, 0, 100) . '...' : $message) . "\n\n";

// Проверка наличия контакта (sendEmailToContact сам проверит email)
echo "Проверка контакта...\n";
$bitrixContact = $bitrixAPI->getEntityData('contact', $contactId);
if ($bitrixContact && isset($bitrixContact['result'])) {
    echo "  ✓ Контакт найден в Bitrix24\n";
    
    // Проверяем наличие email для информативности
    $emailData = $bitrixContact['result']['EMAIL'] ?? null;
    $bitrixEmail = '';
    if (is_array($emailData)) {
        $bitrixEmail = $emailData[0]['VALUE'] ?? $emailData[0] ?? '';
    } else {
        $bitrixEmail = $emailData ?? '';
    }
    
    if (empty($bitrixEmail)) {
        echo "  ⚠ ПРЕДУПРЕЖДЕНИЕ: У контакта не указан email адрес в Bitrix24\n";
        echo "  sendEmailToContact попытается найти email в локальном хранилище\n\n";
    } else {
        echo "  Email в Bitrix24: {$bitrixEmail}\n\n";
    }
} else {
    echo "  ✗ ОШИБКА: Контакт не найден в Bitrix24\n";
    echo "  Response: " . json_encode($bitrixContact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    echo "Письмо не может быть отправлено.\n";
    exit(1);
}

// Проверка настройки email_from
$emailFrom = $config['bitrix24']['email_from'] ?? '';
if (empty($emailFrom)) {
    echo "⚠ ПРЕДУПРЕЖДЕНИЕ: Настройка 'email_from' не задана в конфигурации.\n";
    echo "Bitrix24 будет использовать адрес отправителя по умолчанию.\n";
    echo "Для настройки добавьте в src/config/bitrix24.php:\n";
    echo "  'email_from' => EnvLoader::get('BITRIX24_EMAIL_FROM', ''),\n\n";
} else {
    echo "✓ Настройка email_from: {$emailFrom}\n\n";
}

// Отправка письма
echo "--- ОТПРАВКА ПИСЬМА ---\n";
echo "Отправка запроса в Bitrix24...\n";

$result = $bitrixAPI->sendEmailToContact($contactId, $subject, $message, $localStorage);

if ($result && isset($result['result'])) {
    $activityId = $result['result'] ?? null;
    echo "✓ УСПЕХ: Письмо отправлено!\n";
    echo "  Activity ID: " . ($activityId ?: 'не указан') . "\n";
    
    if (isset($result['time'])) {
        echo "  Время выполнения: {$result['time']} сек\n";
    }
    
    echo "\n";
    echo "Проверьте:\n";
    echo "  1. В карточке контакта в Bitrix24 должна появиться активность (письмо)\n";
    echo "  2. Письмо должно быть отправлено на email адрес контакта\n";
    echo "  3. Проверьте логи в файле: " . $config['logging']['file'] . "\n";
    echo "\n";
    
    if (is_array($result)) {
        echo "Полный ответ API:\n";
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
    }
} else {
    echo "✗ ОШИБКА: Не удалось отправить письмо\n";
    
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
    echo "  1. У контакта не указан email адрес\n";
    echo "  2. Не настроен почтовый сервер в Bitrix24\n";
    echo "  3. Недостаточно прав у webhook для отправки писем\n";
    echo "  4. Неверный формат данных в запросе\n";
    echo "\n";
    echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";
    exit(1);
}

echo "=== ТЕСТИРОВАНИЕ ЗАВЕРШЕНО ===\n";

