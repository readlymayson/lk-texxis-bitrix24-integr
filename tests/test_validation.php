<?php
# -*- coding: utf-8 -*-

/**
 * Тесты валидации webhook запросов от Битрикс24
 */

require_once __DIR__ . '/../src/classes/Logger.php';

/**
 * Mock класс для Bitrix24API с фокусом на валидацию
 */
class MockBitrix24APIValidation
{
    private $config;
    private $logger;

    public function __construct($config, $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function validateWebhookRequest($headers, $body)
    {
        try {
            // Проверка наличия необходимых заголовков
            if (!isset($headers['User-Agent']) || !str_contains($headers['User-Agent'], 'Bitrix24')) {
                $this->logger->warning('Invalid User-Agent in webhook request', ['headers' => $headers]);
                return false;
            }

            // Проверка типа контента
            if (!isset($headers['Content-Type']) || !str_contains($headers['Content-Type'], 'application/json')) {
                $this->logger->warning('Invalid Content-Type in webhook request', ['content_type' => $headers['Content-Type'] ?? 'not set']);
                return false;
            }

            // Валидация JSON тела запроса
            $data = json_decode($body, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Invalid JSON in webhook body', ['error' => json_last_error_msg(), 'body' => $body]);
                return false;
            }

            $this->logger->debug('Webhook request validated successfully');
            return $data;

        } catch (Exception $e) {
            $this->logger->error('Error validating webhook request', [
                'error' => $e->getMessage(),
                'headers' => $headers
            ]);
            return false;
        }
    }
}

/**
 * Тестовые данные для валидации
 */
$validationTests = [
    'valid_webhook' => [
        'name' => 'Валидный webhook запрос',
        'headers' => [
            'User-Agent' => 'Bitrix24 Webhook Service',
            'Content-Type' => 'application/json',
            'X-Bitrix24-Signature' => 'test-signature'
        ],
        'body' => json_encode([
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => [
                'FIELDS' => [
                    'ID' => '12345',
                    'NAME' => 'Тест'
                ]
            ]
        ]),
        'expected' => true
    ],

    'invalid_user_agent' => [
        'name' => 'Неверный User-Agent',
        'headers' => [
            'User-Agent' => 'Some Other Service',
            'Content-Type' => 'application/json'
        ],
        'body' => json_encode(['event' => 'ONCRMCONTACTUPDATE']),
        'expected' => false
    ],

    'invalid_content_type' => [
        'name' => 'Неверный Content-Type',
        'headers' => [
            'User-Agent' => 'Bitrix24 Webhook Service',
            'Content-Type' => 'text/plain'
        ],
        'body' => 'not json data',
        'expected' => false
    ],

    'invalid_json' => [
        'name' => 'Неверный JSON',
        'headers' => [
            'User-Agent' => 'Bitrix24 Webhook Service',
            'Content-Type' => 'application/json'
        ],
        'body' => '{"invalid": json syntax}',
        'expected' => false
    ],

    'empty_body' => [
        'name' => 'Пустое тело запроса',
        'headers' => [
            'User-Agent' => 'Bitrix24 Webhook Service',
            'Content-Type' => 'application/json'
        ],
        'body' => '',
        'expected' => false
    ],

    'missing_headers' => [
        'name' => 'Отсутствующие заголовки',
        'headers' => [],
        'body' => json_encode(['event' => 'ONCRMCONTACTUPDATE']),
        'expected' => false
    ]
];

/**
 * Тестовая конфигурация
 */
$config = [
    'logging' => [
        'enabled' => true,
        'level' => 'DEBUG',
        'file' => __DIR__ . '/../src/logs/test_validation.log',
        'max_size' => 10 * 1024 * 1024,
    ]
];

/**
 * Инициализация компонентов
 */
$logger = new Logger($config);
$bitrixAPI = new MockBitrix24APIValidation($config, $logger);

/**
 * Функция для запуска теста валидации
 */
function runValidationTest($testName, $testData, $bitrixAPI, $logger)
{
    echo "\n" . str_repeat("-", 50) . "\n";
    echo "ТЕСТ ВАЛИДАЦИИ: {$testData['name']}\n";
    echo str_repeat("-", 50) . "\n";

    $logger->info("Starting validation test: {$testName}");

    $result = $bitrixAPI->validateWebhookRequest($testData['headers'], $testData['body']);

    $success = ($result !== false) === $testData['expected'];

    echo "ОЖИДАЕТСЯ: " . ($testData['expected'] ? "ВАЛИДНЫЙ" : "НЕВАЛИДНЫЙ") . "\n";
    echo "ПОЛУЧЕНО: " . ($result !== false ? "ВАЛИДНЫЙ" : "НЕВАЛИДНЫЙ") . "\n";
    echo "РЕЗУЛЬТАТ: " . ($success ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН") . "\n";

    if (!$success) {
        echo "ОШИБКА: Тест не прошел!\n";
    }

    return $success;
}

/**
 * Запуск всех тестов валидации
 */
echo "НАЧАЛО ТЕСТИРОВАНИЯ ВАЛИДАЦИИ WEBHOOK ЗАПРОСОВ\n";
echo str_repeat("=", 60) . "\n";

$results = [];
$passed = 0;
$total = count($validationTests);

foreach ($validationTests as $testName => $testData) {
    $results[$testName] = runValidationTest($testName, $testData, $bitrixAPI, $logger);
    if ($results[$testName]) $passed++;
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "ИТОГИ ТЕСТИРОВАНИЯ ВАЛИДАЦИИ:\n";
echo str_repeat("=", 60) . "\n";

foreach ($results as $testName => $result) {
    $displayName = $validationTests[$testName]['name'];
    $status = $result ? "✓ ПРОЙДЕН" : "✗ ПРОВАЛЕН";
    echo sprintf("%-25s: %s\n", $displayName, $status);
}

echo "\nПРОЙДЕНО: {$passed}/{$total} тестов валидации\n";

if ($passed === $total) {
    echo "✓ ВСЕ ТЕСТЫ ВАЛИДАЦИИ ПРОЙДЕНЫ УСПЕШНО!\n";
} else {
    echo "⚠ НЕКОТОРЫЕ ТЕСТЫ ВАЛИДАЦИИ ПРОВАЛЕНЫ\n";
}

echo "\nЛоги сохранены в: " . $config['logging']['file'] . "\n";

?>
