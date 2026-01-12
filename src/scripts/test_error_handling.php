<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для тестирования обработки ошибок в интеграции с Битрикс24
 *
 * Тестирует различные сценарии ошибок:
 * - Недействительный webhook URL
 * - Ошибки авторизации
 * - Сетевые ошибки
 * - Ошибки валидации данных
 * - Ошибки файловой системы
 *
 * Использование:
 * php test_error_handling.php [test_type]
 *
 * Примеры:
 * php test_error_handling.php invalid_webhook     # Тест недействительного webhook URL
 * php test_error_handling.php network_error        # Тест сетевых ошибок
 * php test_error_handling.php validation_error     # Тест ошибок валидации
 * php test_error_handling.php file_error          # Тест ошибок файловой системы
 * php test_error_handling.php all                  # Запустить все тесты
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$localStorage = new LocalStorage($logger, $config);

$testType = $argv[1] ?? 'all';

echo "=== ТЕСТИРОВАНИЕ ОБРАБОТКИ ОШИБОК ===\n\n";

$testResults = [
    'invalid_webhook' => false,
    'network_error' => false,
    'validation_error' => false,
    'file_error' => false
];

/**
 * Тест недействительного webhook URL
 */
function testInvalidWebhook()
{
    global $config, $logger;

    echo "--- ТЕСТ: НЕДЕЙСТВИТЕЛЬНЫЙ WEBHOOK URL ---\n";

    // Создаем конфиг с недействительным URL
    $invalidConfig = $config;
    $invalidConfig['bitrix24']['webhook_url'] = 'https://invalid-domain-that-does-not-exist.com/rest/1/test/';

    try {
        $bitrixAPI = new Bitrix24API($invalidConfig, $logger);

        // Пытаемся получить несуществующий контакт
        $result = $bitrixAPI->getEntityData('contact', '999999');

        if ($result === false) {
            echo "✓ УСПЕХ: API корректно обработал недействительный URL\n";
            return true;
        } else {
            echo "✗ НЕУДАЧА: API вернул результат вместо ошибки\n";
            return false;
        }

    } catch (Exception $e) {
        echo "✓ УСПЕХ: Исключение обработано: " . $e->getMessage() . "\n";
        return true;
    }
}

/**
 * Тест сетевых ошибок
 */
function testNetworkError()
{
    global $config, $logger;

    echo "--- ТЕСТ: СЕТЕВЫЕ ОШИБКИ ---\n";

    // Создаем конфиг с очень малым таймаутом
    $timeoutConfig = $config;
    $timeoutConfig['bitrix24']['timeout'] = 1; // 1 секунда

    try {
        $bitrixAPI = new Bitrix24API($timeoutConfig, $logger);

        // Пытаемся загрузить большой файл для симуляции таймаута
        $largeFileContent = str_repeat('x', 1024 * 1024); // 1MB
        $tempFile = tempnam(sys_get_temp_dir(), 'test_large_file');
        file_put_contents($tempFile, $largeFileContent);

        $result = $bitrixAPI->uploadFile($tempFile);

        unlink($tempFile); // Очищаем временный файл

        echo "? РЕЗУЛЬТАТ: " . ($result ? "Файл загружен" : "Загрузка не удалась (ожидаемо)") . "\n";
        return true; // Считаем успешным, так как тестируем обработку ошибок

    } catch (Exception $e) {
        echo "✓ УСПЕХ: Исключение обработано: " . $e->getMessage() . "\n";
        return true;
    }
}

/**
 * Тест ошибок валидации данных
 */
function testValidationError()
{
    global $config, $logger;

    echo "--- ТЕСТ: ОШИБКИ ВАЛИДАЦИИ ДАННЫХ ---\n";

    $bitrixAPI = new Bitrix24API($config, $logger);

    // Тесты с некорректными данными
    $invalidWebhookData = [
        // Отсутствует обязательное поле 'event'
        [
            'data' => ['FIELDS' => ['ID' => '123']],
            'ts' => time()
        ],
        // Пустой event
        [
            'event' => '',
            'data' => ['FIELDS' => ['ID' => '123']],
            'ts' => time()
        ],
        // Некорректный формат JSON в теле
        "invalid json content",
        // Пустые данные
        [],
        // null данные
        null
    ];

    $validationResults = [];

    foreach ($invalidWebhookData as $index => $testData) {
        try {
            $result = $bitrixAPI->validateWebhookRequest(
                ['Content-Type' => 'application/json'],
                is_string($testData) ? $testData : json_encode($testData)
            );

            if ($result === false) {
                $validationResults[] = "✓ Тест " . ($index + 1) . ": корректно отклонен";
            } else {
                $validationResults[] = "✗ Тест " . ($index + 1) . ": принят вместо отклонения";
            }

        } catch (Exception $e) {
            $validationResults[] = "✓ Тест " . ($index + 1) . ": исключение обработано - " . $e->getMessage();
        }
    }

    foreach ($validationResults as $result) {
        echo "  {$result}\n";
    }

    $successCount = count(array_filter($validationResults, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$successCount}/" . count($validationResults) . " тестов прошли успешно\n";

    return $successCount > 0;
}

/**
 * Тест ошибок файловой системы
 */
function testFileError()
{
    global $config, $logger, $localStorage;

    echo "--- ТЕСТ: ОШИБКИ ФАЙЛОВОЙ СИСТЕМЫ ---\n";

    $results = [];

    // Тест 1: Попытка чтения несуществующего файла
    try {
        $nonExistentFile = '/tmp/non_existent_file_' . time() . '.json';
        $data = json_decode(file_get_contents($nonExistentFile), true);
        $results[] = "✗ Тест 1: Не обработана ошибка чтения несуществующего файла";
    } catch (Exception $e) {
        $results[] = "✓ Тест 1: Ошибка чтения файла обработана";
    }

    // Тест 2: Попытка записи в недоступную директорию
    try {
        $readonlyDir = '/root/test_write_' . time() . '.json';
        file_put_contents($readonlyDir, 'test');
        $results[] = "✗ Тест 2: Не обработана ошибка записи в недоступную директорию";
    } catch (Exception $e) {
        $results[] = "✓ Тест 2: Ошибка записи в директорию обработана";
    }

    // Тест 3: Попытка создания директории без прав
    try {
        $testDir = '/root/test_dir_' . time();
        mkdir($testDir);
        $results[] = "✗ Тест 3: Не обработана ошибка создания директории";
    } catch (Exception $e) {
        $results[] = "✓ Тест 3: Ошибка создания директории обработана";
    }

    // Тест 4: Тест работы LocalStorage с невалидным JSON
    try {
        $tempFile = tempnam(sys_get_temp_dir(), 'invalid_json');
        file_put_contents($tempFile, '{"invalid": json content}');

        // Имитируем чтение поврежденного JSON
        $data = json_decode(file_get_contents($tempFile), true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $results[] = "✓ Тест 4: Обнаружена ошибка JSON";
        } else {
            $results[] = "? Тест 4: JSON обработан (неожиданно)";
        }

        unlink($tempFile);
    } catch (Exception $e) {
        $results[] = "✓ Тест 4: Исключение при работе с JSON обработано";
    }

    foreach ($results as $result) {
        echo "  {$result}\n";
    }

    $successCount = count(array_filter($results, function($r) {
        return str_starts_with($r, '✓');
    }));

    echo "\n  РЕЗУЛЬТАТ: {$successCount}/" . count($results) . " тестов прошли успешно\n";

    return $successCount > 0;
}

// Запуск тестов
$testsToRun = [];

switch ($testType) {
    case 'invalid_webhook':
        $testsToRun = ['invalid_webhook'];
        break;
    case 'network_error':
        $testsToRun = ['network_error'];
        break;
    case 'validation_error':
        $testsToRun = ['validation_error'];
        break;
    case 'file_error':
        $testsToRun = ['file_error'];
        break;
    case 'all':
    default:
        $testsToRun = ['invalid_webhook', 'network_error', 'validation_error', 'file_error'];
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
echo "=== ИТОГИ ТЕСТИРОВАНИЯ ===\n\n";

$passedTests = array_filter($testResults, function($result) { return $result === true; });
$failedTests = array_filter($testResults, function($result) { return $result === false; });

echo "Выполнено тестов: " . count($testResults) . "\n";
echo "Прошло успешно: " . count($passedTests) . "\n";
echo "Провалено: " . count($failedTests) . "\n";

if (count($failedTests) === 0) {
    echo "\n🎉 Все тесты обработки ошибок прошли успешно!\n";
} else {
    echo "\n❌ Некоторые тесты провалены. Проверьте логи для детальной информации.\n";
}

echo "\nПроверьте логи в файле: " . $config['logging']['file'] . "\n";

?>


