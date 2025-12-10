<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для тестирования webhook endpoint
 * Позволяет отправить тестовый запрос и проверить, корректно ли работает прием событий
 * 
 * Использование:
 * php test_webhook.php [event_type] [entity_id]
 * 
 * Примеры:
 * php test_webhook.php                                    # Тест с параметрами по умолчанию
 * php test_webhook.php ONCRMCONTACTUPDATE 2              # Тест обновления контакта
 * php test_webhook.php ONCRMCOMPANYUPDATE 4              # Тест обновления компании
 * php test_webhook.php ONCRMDYNAMICITEMUPDATE 2          # Тест обновления смарт-процесса
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
EnvLoader::load();

// Получаем URL webhook endpoint
$webhookUrl = getenv('WEBHOOK_TEST_URL') ?: 'https://efrolov-dev.ru/application/lk/src/webhooks/bitrix24.php';
$applicationToken = getenv('BITRIX24_APPLICATION_TOKEN') ?: '';

// Параметры командной строки
$eventType = $argv[1] ?? 'ONCRMCONTACTUPDATE';
$entityId = $argv[2] ?? '2';
$entityTypeId = $argv[3] ?? null; // Для смарт-процессов

echo "=== ТЕСТИРОВАНИЕ WEBHOOK ENDPOINT ===\n\n";
echo "URL: {$webhookUrl}\n";
echo "Event: {$eventType}\n";
echo "Entity ID: {$entityId}\n";
if ($entityTypeId) {
    echo "Entity Type ID: {$entityTypeId}\n";
}
echo "\n";

// Формируем тестовые данные в формате Bitrix24
$testData = [
    'event' => $eventType,
    'event_handler_id' => '999', // Тестовый ID обработчика
    'data' => [
        'FIELDS' => [
            'ID' => $entityId
        ]
    ],
    'ts' => time(),
    'auth' => [
        'domain' => 'b24-11ue58.bitrix24.ru',
        'client_endpoint' => 'https://b24-11ue58.bitrix24.ru/rest/',
        'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
        'member_id' => '42d6c4c35f73b1c45de11528bd16c826',
    ]
];

// Добавляем application_token если он настроен
if (!empty($applicationToken)) {
    $testData['auth']['application_token'] = $applicationToken;
    echo "✓ Application token будет добавлен в запрос\n";
} else {
    echo "⚠ Application token не настроен (BITRIX24_APPLICATION_TOKEN)\n";
}

// Для смарт-процессов добавляем ENTITY_TYPE_ID
if (str_contains($eventType, 'DYNAMICITEM') || str_contains($eventType, 'DYNAMIC')) {
    if ($entityTypeId) {
        $testData['data']['FIELDS']['ENTITY_TYPE_ID'] = $entityTypeId;
    } else {
        // Значение по умолчанию из конфига
        require_once __DIR__ . '/../config/bitrix24.php';
        $config = require_once __DIR__ . '/../config/bitrix24.php';
        $defaultEntityTypeId = $config['bitrix24']['smart_process_id'] ?? '1038';
        $testData['data']['FIELDS']['ENTITY_TYPE_ID'] = $defaultEntityTypeId;
        echo "⚠ Entity Type ID не указан, используется значение по умолчанию: {$defaultEntityTypeId}\n";
    }
}

echo "\n";

// Конвертируем данные в URL-encoded формат (как Bitrix24)
$postData = http_build_query($testData);

echo "Данные запроса:\n";
echo "  Длина: " . strlen($postData) . " байт\n";
echo "  Формат: application/x-www-form-urlencoded\n";
echo "  Preview: " . substr($postData, 0, 200) . "...\n\n";

// Настройки curl
$ch = curl_init($webhookUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Bitrix24 Webhook Engine',
        'Content-Length: ' . strlen($postData)
    ],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_VERBOSE => false,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5
]);

echo "Отправка запроса...\n";
$startTime = microtime(true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
$totalTime = round((microtime(true) - $startTime) * 1000, 2);

curl_close($ch);

echo "\n=== РЕЗУЛЬТАТЫ ===\n\n";

if ($error) {
    echo "✗ ОШИБКА CURL:\n";
    echo "  {$error}\n\n";
    exit(1);
}

echo "HTTP код: {$httpCode}\n";
echo "Время ответа: {$totalTime} мс\n\n";

if ($httpCode === 200) {
    echo "✓ УСПЕХ: Запрос принят и обработан\n";
} elseif ($httpCode === 400) {
    echo "⚠ ОШИБКА ВАЛИДАЦИИ (400): Запрос отклонен на этапе валидации\n";
} elseif ($httpCode === 405) {
    echo "⚠ ОШИБКА МЕТОДА (405): Используйте POST метод\n";
} elseif ($httpCode === 500) {
    echo "✗ ОШИБКА СЕРВЕРА (500): Внутренняя ошибка при обработке\n";
} else {
    echo "⚠ НЕОЖИДАННЫЙ КОД: {$httpCode}\n";
}

echo "\nОтвет сервера:\n";
$responseData = json_decode($response, true);
if ($responseData !== null) {
    echo json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo $response . "\n";
}

echo "\n";

// Проверяем логи
$logFile = __DIR__ . '/../logs/bitrix24_webhooks.log';
if (file_exists($logFile)) {
    echo "Проверка логов...\n";
    $logContent = file_get_contents($logFile);
    $lines = explode("\n", $logContent);
    $recentLines = array_slice($lines, -10); // Последние 10 строк
    
    echo "Последние записи в логе:\n";
    foreach ($recentLines as $line) {
        if (!empty(trim($line))) {
            // Ищем записи, связанные с нашим тестом
            if (str_contains($line, 'WEBHOOK REQUEST RECEIVED') || 
                str_contains($line, 'WEBHOOK VALIDATION') ||
                str_contains($line, 'EVENT PROCESSING') ||
                str_contains($line, 'ERROR') ||
                str_contains($line, 'WARNING')) {
                echo "  " . $line . "\n";
            }
        }
    }
} else {
    echo "⚠ Файл логов не найден: {$logFile}\n";
}

echo "\n=== ТЕСТ ЗАВЕРШЕН ===\n";
echo "\nРекомендации:\n";
echo "1. Проверьте файл логов для детальной информации: {$logFile}\n";
echo "2. Убедитесь, что endpoint доступен: {$webhookUrl}\n";
echo "3. Проверьте настройки application_token в .env файле\n";
echo "4. Проверьте права доступа к директории логов\n";

