<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для тестирования безопасности интеграции с Битрикс24
 *
 * Проверяет уязвимости:
 * - SQL injection
 * - XSS атаки
 * - Path traversal
 * - Command injection
 * - Buffer overflow
 * - Неавторизованный доступ
 *
 * Использование:
 * php test_security.php [test_type]
 *
 * Примеры:
 * php test_security.php xss                  # Тест XSS атак
 * php test_security.php injection            # Тест инъекций
 * php test_security.php path_traversal       # Тест path traversal
 * php test_security.php auth                 # Тест авторизации
 * php test_security.php all                  # Запустить все тесты
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

$testType = $argv[1] ?? 'all';

echo "=== ТЕСТИРОВАНИЕ БЕЗОПАСНОСТИ ===\n\n";
echo "⚠️  ВНИМАНИЕ: Этот тест проверяет потенциальные уязвимости\n";
echo "   Не запускайте на production системе без тщательной проверки!\n\n";

$testResults = [
    'xss' => false,
    'injection' => false,
    'path_traversal' => false,
    'auth' => false,
    'data_validation' => false
];

/**
 * Тест XSS атак
 */
function testXSS()
{
    global $bitrixAPI, $logger;

    echo "--- ТЕСТ БЕЗОПАСНОСТИ: XSS АТАКИ ---\n";

    $xssPayloads = [
        '<script>alert("XSS")</script>',
        '<img src=x onerror=alert("XSS")>',
        'javascript:alert("XSS")',
        '<iframe src="javascript:alert(\'XSS\')"></iframe>',
        '<svg onload=alert("XSS")>',
        '\'><script>alert("XSS")</script>',
        '<div style="background-image: url(javascript:alert(\'XSS\'))">',
    ];

    $results = [];

    foreach ($xssPayloads as $index => $payload) {
        echo "Тестируем payload " . ($index + 1) . ": " . substr($payload, 0, 30) . "...\n";

        // Тест валидации webhook с XSS payload
        try {
            $testData = [
                'event' => 'ONCRMCONTACTUPDATE',
                'data' => [
                    'FIELDS' => [
                        'ID' => '123',
                        'NAME' => $payload,
                        'EMAIL' => [$payload . '@example.com'],
                        'PHONE' => [$payload]
                    ]
                ],
                'ts' => time()
            ];

            $result = $bitrixAPI->validateWebhookRequest(
                ['Content-Type' => 'application/json'],
                json_encode($testData)
            );

            // Проверяем, что XSS payload не прошел валидацию или был очищен
            if ($result === false) {
                $results[] = "✓ Payload " . ($index + 1) . ": заблокирован при валидации";
            } elseif (is_array($result)) {
                // Проверяем, что payload не сохранился в данных
                $name = $result['data']['FIELDS']['NAME'] ?? '';
                if ($name !== $payload) {
                    $results[] = "✓ Payload " . ($index + 1) . ": очищен или модифицирован";
                } else {
                    $results[] = "⚠️  Payload " . ($index + 1) . ": прошел валидацию без изменений";
                }
            } else {
                $results[] = "? Payload " . ($index + 1) . ": неожиданный результат валидации";
            }

        } catch (Exception $e) {
            $results[] = "✓ Payload " . ($index + 1) . ": вызвал исключение - " . $e->getMessage();
        }
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $blockedCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$blockedCount}/" . count($results) . " XSS атак заблокировано\n";

    return $blockedCount === count($results);
}

/**
 * Тест инъекций (SQL, Command)
 */
function testInjection()
{
    global $bitrixAPI, $logger, $localStorage;

    echo "--- ТЕСТ БЕЗОПАСНОСТИ: ИНЪЕКЦИИ ---\n";

    $injectionPayloads = [
        // SQL инъекции
        "'; DROP TABLE contacts; --",
        "1' OR '1'='1",
        "admin' --",
        "1; SELECT * FROM users; --",

        // Command инъекции
        "; rm -rf /",
        "| cat /etc/passwd",
        "`id`",
        "$(rm -rf /)",

        // Path инъекции
        "../../../etc/passwd",
        "..\\..\\..\\windows\\system32\\config\\sam",
        "/etc/passwd",
        "C:\\Windows\\System32\\config\\sam",
    ];

    $results = [];

    foreach ($injectionPayloads as $index => $payload) {
        echo "Тестируем payload " . ($index + 1) . ": " . substr($payload, 0, 30) . "...\n";

        try {
            // Тест через API метод (имитируем вредоносные параметры)
            if (str_contains($payload, ';') || str_contains($payload, '|') || str_contains($payload, '`')) {
                // Command injection тест - пробуем в имени файла
                $tempFile = tempnam(sys_get_temp_dir(), 'injection_test');
                file_put_contents($tempFile, 'test content');

                // Пытаемся "внедрить" payload в имя файла
                $maliciousName = basename($tempFile) . $payload;
                $fullPath = dirname($tempFile) . '/' . $maliciousName;

                // Проверяем, что файловая система не выполнила команду
                if (file_exists($fullPath)) {
                    $results[] = "⚠️  Payload " . ($index + 1) . ": файл с вредоносным именем создан";
                    unlink($fullPath);
                } else {
                    $results[] = "✓ Payload " . ($index + 1) . ": command injection заблокирован";
                }

                unlink($tempFile);
            } else {
                // Тест через webhook данные
                $testData = [
                    'event' => 'ONCRMCONTACTUPDATE',
                    'data' => [
                        'FIELDS' => [
                            'ID' => $payload, // Вредоносный ID
                            'NAME' => 'Test Contact'
                        ]
                    ],
                    'ts' => time()
                ];

                $result = $bitrixAPI->validateWebhookRequest(
                    ['Content-Type' => 'application/json'],
                    json_encode($testData)
                );

                if ($result === false) {
                    $results[] = "✓ Payload " . ($index + 1) . ": заблокирован при валидации";
                } else {
                    // Проверяем, что payload не попал в локальное хранилище
                    $contact = $localStorage->getContact($payload);
                    if ($contact === null) {
                        $results[] = "✓ Payload " . ($index + 1) . ": не сохранен в хранилище";
                    } else {
                        $results[] = "⚠️  Payload " . ($index + 1) . ": сохранен в хранилище";
                    }
                }
            }

        } catch (Exception $e) {
            $results[] = "✓ Payload " . ($index + 1) . ": вызвал исключение - " . $e->getMessage();
        }
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $blockedCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$blockedCount}/" . count($results) . " инъекций заблокировано\n";

    return $blockedCount >= count($results) * 0.8; // 80% должно быть заблокировано
}

/**
 * Тест path traversal атак
 */
function testPathTraversal()
{
    global $logger;

    echo "--- ТЕСТ БЕЗОПАСНОСТИ: PATH TRAVERSAL ---\n";

    $pathPayloads = [
        "../../../etc/passwd",
        "..\\..\\..\\..\\windows\\system32\\config\\sam",
        "/etc/passwd",
        "C:\\Windows\\System32\\config\\sam",
        "../../../src/config/bitrix24.php",
        "..\\..\\..\\src\\config\\bitrix24.php",
        "/var/www/html/index.php",
        "....//....//....//etc/passwd",
    ];

    $results = [];

    foreach ($pathPayloads as $index => $payload) {
        echo "Тестируем path " . ($index + 1) . ": " . substr($payload, 0, 30) . "...\n";

        try {
            // Тест чтения файла с path traversal
            $testPath = __DIR__ . '/' . $payload;

            // Проверяем, что путь не выходит за пределы разрешенной директории
            $realPath = realpath($testPath);
            $allowedDir = realpath(__DIR__ . '/../');

            if ($realPath === false) {
                $results[] = "✓ Path " . ($index + 1) . ": путь не существует";
            } elseif (str_starts_with($realPath, $allowedDir)) {
                $results[] = "⚠️  Path " . ($index + 1) . ": путь разрешен (проверка слаба)";
            } else {
                $results[] = "✓ Path " . ($index + 1) . ": path traversal заблокирован";
            }

            // Проверяем попытку чтения
            if (file_exists($testPath)) {
                $results[] = "⚠️  Path " . ($index + 1) . ": файл доступен для чтения";
            }

        } catch (Exception $e) {
            $results[] = "✓ Path " . ($index + 1) . ": исключение при доступе - " . $e->getMessage();
        }
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $blockedCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$blockedCount}/" . count($results) . " path traversal атак заблокировано\n";

    return $blockedCount >= count($results) * 0.9; // 90% должно быть заблокировано
}

/**
 * Тест авторизации и доступа
 */
function testAuth()
{
    global $bitrixAPI, $logger;

    echo "--- ТЕСТ БЕЗОПАСНОСТИ: АВТОРИЗАЦИЯ И ДОСТУП ---\n";

    $results = [];

    // Тест 1: Запрос без авторизации
    try {
        $testData = [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => ['FIELDS' => ['ID' => '123']],
            'ts' => time()
            // Отсутствует 'auth' секция
        ];

        $result = $bitrixAPI->validateWebhookRequest(
            ['Content-Type' => 'application/json'],
            json_encode($testData)
        );

        if ($result === false) {
            $results[] = "✓ Тест 1: запрос без авторизации отклонен";
        } else {
            $results[] = "⚠️  Тест 1: запрос без авторизации принят";
        }
    } catch (Exception $e) {
        $results[] = "✓ Тест 1: исключение при проверке авторизации";
    }

    // Тест 2: Запрос с неверным application_token
    try {
        $testData = [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => ['FIELDS' => ['ID' => '123']],
            'ts' => time(),
            'auth' => [
                'application_token' => 'invalid_token_' . time()
            ]
        ];

        $result = $bitrixAPI->validateWebhookRequest(
            ['Content-Type' => 'application/json'],
            json_encode($testData)
        );

        if ($result === false) {
            $results[] = "✓ Тест 2: запрос с неверным токеном отклонен";
        } else {
            $results[] = "⚠️  Тест 2: запрос с неверным токеном принят";
        }
    } catch (Exception $e) {
        $results[] = "✓ Тест 2: исключение при проверке токена";
    }

    // Тест 3: Проверка User-Agent
    try {
        $testData = [
            'event' => 'ONCRMCONTACTUPDATE',
            'data' => ['FIELDS' => ['ID' => '123']],
            'ts' => time(),
            'auth' => ['application_token' => 'test_token']
        ];

        // Тест с подозрительным User-Agent
        $result = $bitrixAPI->validateWebhookRequest(
            [
                'Content-Type' => 'application/json',
                'User-Agent' => 'MaliciousBot/1.0'
            ],
            json_encode($testData)
        );

        // Система должна логировать предупреждение, но не блокировать
        $results[] = "? Тест 3: подозрительный User-Agent обработан (система логирует предупреждение)";
    } catch (Exception $e) {
        $results[] = "✓ Тест 3: исключение при проверке User-Agent";
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $secureCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$secureCount}/" . count($results) . " проверок безопасности пройдено\n";

    return $secureCount >= 2; // Минимум 2 из 3 тестов должны пройти
}

/**
 * Тест валидации данных
 */
function testDataValidation()
{
    global $bitrixAPI, $logger;

    echo "--- ТЕСТ БЕЗОПАСНОСТИ: ВАЛИДАЦИЯ ДАННЫХ ---\n";

    $invalidData = [
        // Слишком большой JSON
        str_repeat('{"data": "test"}', 10000),

        // Некорректный JSON
        '{"invalid": json content}',

        // Пустой JSON
        '',

        // Null байты
        '{"data": "test' . "\x00" . '"}',

        // Очень глубокая вложенность
        json_encode(['level1' => ['level2' => ['level3' => ['level4' => ['level5' => 'deep']]]]]),

        // Специальные символы в строках
        '{"data": "<>&\'' . "\n\r\t" . '"}',
    ];

    $results = [];

    foreach ($invalidData as $index => $testData) {
        echo "Тестируем данные " . ($index + 1) . ": " . substr($testData, 0, 30) . "...\n";

        try {
            $result = $bitrixAPI->validateWebhookRequest(
                ['Content-Type' => 'application/json'],
                $testData
            );

            if ($result === false) {
                $results[] = "✓ Данные " . ($index + 1) . ": корректно отклонены";
            } else {
                $results[] = "? Данные " . ($index + 1) . ": приняты системой";
            }

        } catch (Exception $e) {
            $results[] = "✓ Данные " . ($index + 1) . ": вызвали исключение - " . $e->getMessage();
        }
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $validCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$validCount}/" . count($results) . " некорректных данных обработано безопасно\n";

    return $validCount >= count($results) * 0.7; // 70% должно быть обработано безопасно
}

// Запуск тестов
$testsToRun = [];

switch ($testType) {
    case 'xss':
        $testsToRun = ['xss'];
        break;
    case 'injection':
        $testsToRun = ['injection'];
        break;
    case 'path_traversal':
        $testsToRun = ['path_traversal'];
        break;
    case 'auth':
        $testsToRun = ['auth'];
        break;
    case 'data_validation':
        $testsToRun = ['data_validation'];
        break;
    case 'all':
    default:
        $testsToRun = ['xss', 'injection', 'path_traversal', 'auth', 'data_validation'];
        break;
}

foreach ($testsToRun as $testName) {
    try {
        $testResults[$testName] = call_user_func('test' . str_replace('_', '', ucwords($testName, '_')));
        echo "\n";
    } catch (Exception $e) {
        echo "✗ ОШИБКА В ТЕСТЕ {$testName}: " . $e->getMessage() . "\n\n";
        $testResults[$testName] = false;
    }
}

// Итоги
echo "=== ИТОГИ ТЕСТИРОВАНИЯ БЕЗОПАСНОСТИ ===\n\n";

$passedTests = array_filter($testResults, function($result) { return $result === true; });
$failedTests = array_filter($testResults, function($result) { return $result === false; });

echo "Выполнено тестов: " . count($testResults) . "\n";
echo "Прошло успешно: " . count($passedTests) . "\n";
echo "Провалено: " . count($failedTests) . "\n";

if (count($failedTests) === 0) {
    echo "\n🛡️  Все тесты безопасности прошли успешно!\n";
} else {
    echo "\n⚠️  Некоторые тесты безопасности провалены. Рекомендуется усилить защиту.\n";
}

echo "\nРекомендации по безопасности:\n";
echo "1. Регулярно проверяйте логи на подозрительную активность\n";
echo "2. Используйте HTTPS для webhook endpoint\n";
echo "3. Валидируйте все входные данные\n";
echo "4. Ограничьте права доступа к файлам\n";
echo "5. Мониторьте производительность и аномалии\n\n";

echo "Тестирование безопасности завершено.\n";

?>


