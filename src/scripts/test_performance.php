<?php
declare(strict_types=1);
# -*- coding: utf-8 -*-

// Константы для тестирования производительности
const FILE_SIZES = [
    'small' => 1024,        // 1KB
    'medium' => 1024 * 100, // 100KB
    'large' => 1024 * 500   // 500KB
];

const LARGE_DATA_SIZE = 1000;
const API_DELAY_MS = 100000; // 100ms
const FILE_UPLOAD_DELAY_SEC = 1;

// Рекомендуемые значения производительности (мс)
const RECOMMENDED_API_RESPONSE_MS = 1000;
const RECOMMENDED_LOCAL_OP_MS = 10;
const RECOMMENDED_JSON_OP_MS = 100;

/**
 * Скрипт для тестирования производительности интеграции с Битрикс24
 *
 * Измеряет время выполнения различных операций:
 * - Чтение/запись в локальное хранилище
 * - API запросы к Bitrix24
 * - Обработка webhook
 * - Загрузка файлов
 *
 * Использование:
 * php test_performance.php [operation] [iterations]
 *
 * Примеры:
 * php test_performance.php local_storage 100     # Тест локального хранилища (100 итераций)
 * php test_performance.php api_call 10           # Тест API вызовов (10 итераций)
 * php test_performance.php file_upload 5         # Тест загрузки файлов (5 итераций)
 * php test_performance.php all                    # Запустить все тесты
 */

// Проверка зависимостей перед подключением
$requiredFiles = [
    __DIR__ . '/../classes/EnvLoader.php',
    __DIR__ . '/../classes/Logger.php',
    __DIR__ . '/../classes/Bitrix24API.php',
    __DIR__ . '/../classes/LocalStorage.php',
    __DIR__ . '/../config/bitrix24.php'
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        throw new RuntimeException("Required file not found: {$file}");
    }
    if (!is_readable($file)) {
        throw new RuntimeException("Required file is not readable: {$file}");
    }
}

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

/**
 * Оптимизированная запись больших файлов с использованием потоков
 *
 * Записывает большой файл порциями для экономии памяти.
 * Использует потоки вместо загрузки всего файла в память.
 *
 * @param string $filePath Путь к файлу для записи
 * @param int $size Размер файла в байтах
 * @param int $chunkSize Размер порции для записи (по умолчанию 8192 байта)
 * @return bool true при успешной записи, false при ошибке
 */
function writeLargeFile(string $filePath, int $size, int $chunkSize = 8192): bool {
    $handle = fopen($filePath, 'w');
    if ($handle === false) {
        return false;
    }

    try {
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = min($chunkSize, $remaining);
            $data = str_repeat('A', $chunk);

            if (fwrite($handle, $data) === false) {
                return false;
            }

            $remaining -= $chunk;
        }

        return true;
    } finally {
        fclose($handle);
    }
}

/**
 * Создание больших тестовых данных для JSON тестирования
 *
 * Генерирует массив тестовых данных указанного размера для измерения
 * производительности JSON операций с большими объемами данных.
 *
 * @param int $size Количество элементов в массиве (по умолчанию LARGE_DATA_SIZE)
 * @return array Массив тестовых данных
 */
function createLargeTestData(int $size = LARGE_DATA_SIZE): array {
    $data = [];
    for ($i = 0; $i < $size; $i++) {
        $data["item_{$i}"] = [
            'id' => $i,
            'name' => "Элемент {$i}",
            'data' => str_repeat('x', 100),
            'nested' => [
                'prop1' => "Значение {$i}",
                'prop2' => rand(1, 1000),
                'prop3' => ['a', 'b', 'c']
            ]
        ];
    }
    return $data;
}

/**
 * Подготовка тестовых данных контакта
 */
function prepareTestContactData(int $iteration): array {
    return [
        'id' => 'PERF_TEST_' . time() . '_' . $iteration . '_CONTACT',
        'bitrix_id' => '999' . $iteration,
        'name' => 'Тестовый контакт',
        'email' => 'test@example.com',
        'phone' => '+79999999999',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'source' => 'performance_test'
    ];
}

/**
 * Подготовка тестовых данных компании
 */
function prepareTestCompanyData(int $iteration): array {
    return [
        'id' => '999' . $iteration,
        'title' => 'Тестовая компания',
        'email' => 'company@example.com',
        'inn' => '1234567890',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'source' => 'performance_test'
    ];
}

/**
 * Измерение операций чтения
 */
function measureReadOperations(LocalStorage $localStorage): array {
    $timings = [];

    // Тест чтения контактов
    $start = microtime(true);
    $contacts = $localStorage->getAllContacts();
    $end = microtime(true);
    $timings['read_contacts'] = ($end - $start) * 1000;

    // Тест чтения компаний
    $start = microtime(true);
    $companies = $localStorage->getAllCompanies();
    $end = microtime(true);
    $timings['read_companies'] = ($end - $start) * 1000;

    return $timings;
}

/**
 * Измерение операций записи
 */
function measureWriteOperations(int $iteration): array {
    $timings = [];

    $testContact = prepareTestContactData($iteration);
    $testCompany = prepareTestCompanyData($iteration);

    // Тест записи контакта
    $start = microtime(true);
    // Имитируем запись (не сохраняем реально, чтобы не засорять данные)
    $jsonData = json_encode([$testContact['bitrix_id'] => $testContact], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $end = microtime(true);
    $timings['write_contacts'] = ($end - $start) * 1000;

    // Тест записи компании
    $start = microtime(true);
    // Имитируем запись
    $jsonData = json_encode([$testCompany['id'] => $testCompany], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $end = microtime(true);
    $timings['write_companies'] = ($end - $start) * 1000;

    return $timings;
}

/**
 * Вычисление статистики производительности
 *
 * Рассчитывает минимальное, максимальное, среднее время выполнения
 * и общую продолжительность для каждой операции тестирования.
 *
 * @param array $timings Массив времен выполнения ['operation' => [times...]]
 * @return array Массив статистики ['operation' => ['min', 'max', 'avg', 'total']]
 */
function calculateStatistics(array $timings): array {
    $stats = [];
    foreach ($timings as $operation => $times) {
        if (empty($times)) {
            continue;
        }

        $stats[$operation] = [
            'min' => round(min($times), 2),
            'max' => round(max($times), 2),
            'avg' => count($times) > 0 ? round(array_sum($times) / count($times), 2) : 0,
            'total' => round(array_sum($times), 2)
        ];
    }
    return $stats;
}

/**
 * Отображение результатов тестирования локального хранилища
 *
 * Выводит статистику производительности операций локального хранилища
 * в читаемом формате с указанием количества итераций.
 *
 * @param array $stats Массив статистики от calculateStatistics()
 * @param int $iterations Количество выполненных итераций
 * @return void
 */
function displayLocalStorageResults(array $stats, int $iterations): void {
    echo "Результаты ({$iterations} итераций):\n\n";

    foreach ($stats as $operation => $stat) {
        $operationName = str_replace('_', ' ', $operation);
        echo "  " . ucfirst($operationName) . ":\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }
}

/**
 * Отображение результатов тестирования API
 *
 * Выводит статистику производительности API запросов
 * в читаемом формате с указанием количества итераций.
 *
 * @param array $stats Массив статистики от calculateStatistics()
 * @param int $iterations Количество выполненных итераций
 * @return void
 */
function displayApiResults(array $stats, int $iterations): void {
    echo "Результаты ({$iterations} итераций):\n\n";

    foreach ($stats as $operation => $stat) {
        $operationName = str_replace('_', ' ', $operation);
        echo "  " . ucfirst($operationName) . ":\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }
}

/**
 * Отображение результатов тестирования загрузки файлов
 *
 * Выводит статистику производительности загрузки файлов разных размеров
 * в читаемом формате с указанием количества итераций.
 *
 * @param array $stats Массив статистики от calculateStatistics()
 * @param int $iterations Количество выполненных итераций
 * @return void
 */
function displayFileUploadResults(array $stats, int $iterations): void {
    echo "Результаты ({$iterations} итераций):\n\n";

    $sizeNames = [
        'upload_small' => 'Маленький файл (1KB)',
        'upload_medium' => 'Средний файл (100KB)',
        'upload_large' => 'Большой файл (500KB)'
    ];

    foreach ($stats as $operation => $stat) {
        $displayName = $sizeNames[$operation] ?? $operation;
        echo "  {$displayName}:\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }
}

/**
 * Отображение результатов тестирования JSON
 *
 * Выводит статистику производительности JSON операций кодирования/декодирования
 * в читаемом формате с указанием количества итераций.
 *
 * @param array $stats Массив статистики от calculateStatistics()
 * @param int $iterations Количество выполненных итераций
 * @return void
 */
function displayJsonResults(array $stats, int $iterations): void {
    echo "Результаты ({$iterations} итераций):\n\n";

    $operationNames = [
        'encode_small' => 'Кодирование маленького объекта',
        'decode_small' => 'Декодирование маленького объекта',
        'encode_large' => 'Кодирование большого объекта (' . LARGE_DATA_SIZE . ' элементов)',
        'decode_large' => 'Декодирование большого объекта (' . LARGE_DATA_SIZE . ' элементов)'
    ];

    foreach ($stats as $operation => $stat) {
        $displayName = $operationNames[$operation] ?? $operation;
        echo "  {$displayName}:\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }
}

$operation = $argv[1] ?? 'all';
$iterations = (int)($argv[2] ?? 10);

// Валидация входных параметров
$validOperations = ['all', 'local_storage', 'api_call', 'file_upload', 'json_processing'];

if (!is_string($operation)) {
    echo "ERROR: Operation must be a string\n";
    exit(1);
}

if (!in_array($operation, $validOperations, true)) {
    echo "ERROR: Invalid operation '{$operation}'. Valid operations: " . implode(', ', $validOperations) . "\n";
    exit(1);
}

if (!is_int($iterations) || $iterations < 1) {
    echo "ERROR: Iterations must be a positive integer\n";
    exit(1);
}

if ($iterations > 1000) {
    echo "WARNING: Large number of iterations ({$iterations}) may cause performance issues\n";
}

// Проверка доступности временной директории
$tempDir = sys_get_temp_dir();
if (!is_dir($tempDir) || !is_writable($tempDir)) {
    echo "ERROR: Temp directory '{$tempDir}' is not accessible\n";
    exit(1);
}

echo "=== ТЕСТИРОВАНИЕ ПРОИЗВОДИТЕЛЬНОСТИ ===\n\n";
echo "Операция: {$operation}\n";
echo "Итераций: {$iterations}\n\n";

$results = [];

/**
 * Тест производительности локального хранилища
 *
 * Измеряет время выполнения операций чтения и записи в локальное хранилище JSON.
 * Выполняет несколько итераций для получения статистически значимых результатов.
 *
 * @param int $iterations Количество итераций тестирования
 * @param LocalStorage $localStorage Экземпляр локального хранилища для тестирования
 * @param Logger $logger Экземпляр логгера для записи ошибок
 * @return array Массив с результатами тестирования ['operation' => ['min', 'max', 'avg', 'total']]
 */
function testLocalStoragePerformance(int $iterations, LocalStorage $localStorage, Logger $logger): array
{
    $timings = [
        'read_contacts' => [],
        'write_contacts' => [],
        'read_companies' => [],
        'write_companies' => []
    ];

    // Подготовка тестовых данных
    $testContact = [
        'id' => 'PERF_TEST_' . time() . '_CONTACT',
        'bitrix_id' => '999999',
        'name' => 'Тестовый контакт',
        'email' => 'test@example.com',
        'phone' => '+79999999999',
        'status' => 'active',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'source' => 'performance_test'
    ];

    $testCompany = [
        'id' => '999999',
        'title' => 'Тестовая компания',
        'email' => 'company@example.com',
        'inn' => '1234567890',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'source' => 'performance_test'
    ];

    for ($i = 0; $i < $iterations; $i++) {
        try {
            // Тест чтения контактов
            $start = microtime(true);
            $contacts = $localStorage->getAllContacts();
            $end = microtime(true);
            $timings['read_contacts'][] = ($end - $start) * 1000; // в миллисекундах
        } catch (Exception $e) {
            $logger->error("Failed to read contacts in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['read_contacts'][] = -1; // Маркер ошибки
        }

        try {
            // Тест записи контакта
            $start = microtime(true);
            $testContact['id'] = 'PERF_TEST_' . time() . '_' . $i . '_CONTACT';
            $testContact['bitrix_id'] = '999' . $i;
            // Имитируем запись (не сохраняем реально, чтобы не засорять данные)
            $jsonData = json_encode([$testContact['bitrix_id'] => $testContact], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $end = microtime(true);
            $timings['write_contacts'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to write contact in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['write_contacts'][] = -1; // Маркер ошибки
        }

        try {
            // Тест чтения компаний
            $start = microtime(true);
            $companies = $localStorage->getAllCompanies();
            $end = microtime(true);
            $timings['read_companies'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to read companies in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['read_companies'][] = -1; // Маркер ошибки
        }

        try {
            // Тест записи компании
            $start = microtime(true);
            $testCompany['id'] = '999' . $i;
            // Имитируем запись
            $jsonData = json_encode([$testCompany['id'] => $testCompany], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $end = microtime(true);
            $timings['write_companies'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to write company in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['write_companies'][] = -1; // Маркер ошибки
        }
    }

    // Вычисление статистики
    $stats = calculateStatistics($timings);

    echo "Результаты ({$iterations} итераций):\n\n";

    foreach ($stats as $operation => $stat) {
        $operationName = str_replace('_', ' ', $operation);
        echo "  " . ucfirst($operationName) . ":\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }

    return $stats;
}

/**
 * Тест производительности API вызовов
 *
 * Измеряет время выполнения различных API запросов к Bitrix24.
 * Включает получение контактов, компаний и списков сущностей.
 *
 * @param int $iterations Количество итераций тестирования
 * @param Bitrix24API $bitrixAPI Экземпляр API клиента для тестирования
 * @param Logger $logger Экземпляр логгера для записи ошибок
 * @return array Массив с результатами тестирования ['operation' => ['min', 'max', 'avg', 'total']]
 */
function testApiPerformance(int $iterations, Bitrix24API $bitrixAPI, Logger $logger): array
{
    $timings = [
        'get_contact' => [],
        'get_company' => [],
        'list_contacts' => [],
        'list_companies' => []
    ];

    for ($i = 0; $i < $iterations; $i++) {
        try {
            // Тест получения контакта (несуществующего, чтобы измерить только сетевой overhead)
            $start = microtime(true);
            $result = $bitrixAPI->getEntityData('contact', '999999');
            $end = microtime(true);
            $timings['get_contact'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to get contact data in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['get_contact'][] = -1; // Маркер ошибки
        }

        try {
            // Тест получения компании
            $start = microtime(true);
            $result = $bitrixAPI->getEntityData('company', '999999');
            $end = microtime(true);
            $timings['get_company'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to get company data in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['get_company'][] = -1; // Маркер ошибки
        }

        try {
            // Тест списка контактов (с фильтром, чтобы ограничить результаты)
            $start = microtime(true);
            $result = $bitrixAPI->getEntityList('contact', ['ID' => '999999'], ['ID', 'NAME']);
            $end = microtime(true);
            $timings['list_contacts'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to get contact list in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['list_contacts'][] = -1; // Маркер ошибки
        }

        try {
            // Тест списка компаний
            $start = microtime(true);
            $result = $bitrixAPI->getEntityList('company', ['ID' => '999999'], ['ID', 'TITLE']);
            $end = microtime(true);
            $timings['list_companies'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to get company list in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['list_companies'][] = -1; // Маркер ошибки
        }

        // Небольшая пауза между итерациями, чтобы не перегружать API
        usleep(API_DELAY_MS);
    }

    // Вычисление статистики
    $stats = calculateStatistics($timings);

    echo "Результаты ({$iterations} итераций):\n\n";

    foreach ($stats as $operation => $stat) {
        $operationName = str_replace('_', ' ', $operation);
        echo "  " . ucfirst($operationName) . ":\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }

    return $stats;
}

/**
 * Тест производительности загрузки файлов
 *
 * Измеряет время загрузки файлов разных размеров в Bitrix24.
 * Создает временные файлы, загружает их и удаляет после тестирования.
 *
 * @param int $iterations Количество итераций тестирования
 * @param Bitrix24API $bitrixAPI Экземпляр API клиента для тестирования
 * @param Logger $logger Экземпляр логгера для записи ошибок
 * @return array Массив с результатами тестирования ['operation' => ['min', 'max', 'avg', 'total']]
 * @throws RuntimeException Если временная директория недоступна
 */
function testFileUploadPerformance(int $iterations, Bitrix24API $bitrixAPI, Logger $logger): array
{
    $timings = [
        'upload_small' => [],
        'upload_medium' => [],
        'upload_large' => []
    ];

    // Проверка прав доступа к временной директории
    $tempDir = sys_get_temp_dir();
    if (!is_dir($tempDir) || !is_writable($tempDir)) {
        $logger->error("Temp directory is not writable", ['temp_dir' => $tempDir]);
        echo "ОШИБКА: Временная директория недоступна для записи\n";
        return $timings;
    }

    $createdFiles = []; // Массив для отслеживания созданных файлов

    try {
        for ($i = 0; $i < $iterations; $i++) {
            // Создание тестовых файлов разных размеров

        foreach (FILE_SIZES as $sizeName => $size) {
            $tempFile = tempnam($tempDir, 'perf_test_' . $sizeName . '_');

            if ($tempFile === false) {
                $logger->error("Failed to create temp file name", ['size_name' => $sizeName, 'temp_dir' => $tempDir]);
                continue;
            }

            $createdFiles[] = $tempFile; // Добавляем в массив для очистки

            // Оптимизированная запись больших файлов
            if (!writeLargeFile($tempFile, $size)) {
                $logger->error("Failed to write test file content", ['file' => $tempFile, 'size' => $size]);
                continue;
            }

                $start = microtime(true);
                $result = $bitrixAPI->uploadFile($tempFile);
                $end = microtime(true);

                $timings['upload_' . $sizeName][] = ($end - $start) * 1000;

                // Пауза между загрузками
                sleep(FILE_UPLOAD_DELAY_SEC);
            }
        }
    } finally {
        // Гарантированная очистка всех созданных файлов
        foreach ($createdFiles as $file) {
            if (file_exists($file)) {
                if (!unlink($file)) {
                    $logger->warning("Failed to cleanup temp file", ['file' => $file]);
                }
            }
        }
    }

    // Вычисление статистики
    $stats = [];
    foreach ($timings as $operation => $times) {
        if (empty($times)) continue;

        $stats[$operation] = [
            'min' => round(min($times), 2),
            'max' => round(max($times), 2),
            'avg' => count($times) > 0 ? round(array_sum($times) / count($times), 2) : 0,
            'total' => round(array_sum($times), 2)
        ];
    }

    echo "Результаты ({$iterations} итераций):\n\n";

    $sizeNames = [
        'upload_small' => 'Маленький файл (1KB)',
        'upload_medium' => 'Средний файл (100KB)',
        'upload_large' => 'Большой файл (500KB)'
    ];

    foreach ($stats as $operation => $stat) {
        $displayName = $sizeNames[$operation] ?? $operation;
        echo "  {$displayName}:\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }

    return $stats;
}

/**
 * Тест производительности обработки JSON
 *
 * Измеряет время кодирования и декодирования JSON данных разных размеров.
 * Тестирует как маленькие объекты, так и большие массивы данных.
 *
 * @param int $iterations Количество итераций тестирования
 * @param Logger $logger Экземпляр логгера для записи ошибок
 * @return array Массив с результатами тестирования ['operation' => ['min', 'max', 'avg', 'total']]
 */
function testJsonPerformance(int $iterations, Logger $logger): array
{
    $timings = [
        'encode_small' => [],
        'decode_small' => [],
        'encode_large' => [],
        'decode_large' => []
    ];

    // Создание тестовых данных разных размеров
    $smallData = [
        'id' => 1,
        'name' => 'Тест',
        'email' => 'test@example.com',
        'active' => true
    ];

    $largeData = createLargeTestData();

    for ($i = 0; $i < $iterations; $i++) {
        try {
            // Тест кодирования маленького объекта
            $start = microtime(true);
            $json = json_encode($smallData, JSON_UNESCAPED_UNICODE);
            $end = microtime(true);
            $timings['encode_small'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to encode small JSON in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['encode_small'][] = -1; // Маркер ошибки
        }

        try {
            // Тест декодирования маленького объекта
            $start = microtime(true);
            $data = json_decode($json ?? '', true);
            $end = microtime(true);
            $timings['decode_small'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to decode small JSON in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['decode_small'][] = -1; // Маркер ошибки
        }

        try {
            // Тест кодирования большого объекта
            $start = microtime(true);
            $json = json_encode($largeData, JSON_UNESCAPED_UNICODE);
            $end = microtime(true);
            $timings['encode_large'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to encode large JSON in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['encode_large'][] = -1; // Маркер ошибки
        }

        try {
            // Тест декодирования большого объекта
            $start = microtime(true);
            $data = json_decode($json ?? '', true);
            $end = microtime(true);
            $timings['decode_large'][] = ($end - $start) * 1000;
        } catch (Exception $e) {
            $logger->error("Failed to decode large JSON in performance test", [
                'iteration' => $i,
                'error' => $e->getMessage()
            ]);
            $timings['decode_large'][] = -1; // Маркер ошибки
        }
    }

    // Вычисление статистики
    $stats = calculateStatistics($timings);

    echo "Результаты ({$iterations} итераций):\n\n";

    $operationNames = [
        'encode_small' => 'Кодирование маленького объекта',
        'decode_small' => 'Декодирование маленького объекта',
        'encode_large' => 'Кодирование большого объекта (' . LARGE_DATA_SIZE . ' элементов)',
        'decode_large' => 'Декодирование большого объекта (' . LARGE_DATA_SIZE . ' элементов)'
    ];

    foreach ($stats as $operation => $stat) {
        $displayName = $operationNames[$operation] ?? $operation;
        echo "  {$displayName}:\n";
        echo "    Min: {$stat['min']} ms\n";
        echo "    Max: {$stat['max']} ms\n";
        echo "    Avg: {$stat['avg']} ms\n";
        echo "    Total: {$stat['total']} ms\n";
        echo "\n";
    }

    return $stats;
}

// Запуск тестов
switch ($operation) {
    case 'local_storage':
        echo "--- ТЕСТ ПРОИЗВОДИТЕЛЬНОСТИ: ЛОКАЛЬНОЕ ХРАНИЛИЩЕ ---\n";
        $results['local_storage'] = testLocalStoragePerformance($iterations, $localStorage, $logger);
        displayLocalStorageResults($results['local_storage'], $iterations);
        echo "\n";
        break;

    case 'api_call':
        echo "--- ТЕСТ ПРОИЗВОДИТЕЛЬНОСТИ: API ВЫЗОВЫ ---\n";
        $results['api_call'] = testApiPerformance($iterations, $bitrixAPI, $logger);
        displayApiResults($results['api_call'], $iterations);
        echo "\n";
        break;

    case 'file_upload':
        $results['file_upload'] = testFileUploadPerformance($iterations, $bitrixAPI, $logger);
        displayFileUploadResults($results['file_upload'], $iterations);
        echo "\n";
        break;

    case 'json_processing':
        echo "--- ТЕСТ ПРОИЗВОДИТЕЛЬНОСТИ: ОБРАБОТКА JSON ---\n";
        $results['json_processing'] = testJsonPerformance($iterations, $logger);
        displayJsonResults($results['json_processing'], $iterations);
        echo "\n";
        break;

    case 'all':
    default:
        echo "Запуск полного тестирования производительности...\n\n";

        $results['local_storage'] = testLocalStoragePerformance(max(1, $iterations / 4), $localStorage, $logger);
        displayLocalStorageResults($results['local_storage'], max(1, $iterations / 4));
        echo "\n";

        $results['api_call'] = testApiPerformance(max(1, $iterations / 10), $bitrixAPI, $logger); // Меньше итераций для API
        displayApiResults($results['api_call'], max(1, $iterations / 10));
        echo "\n";

        $results['file_upload'] = testFileUploadPerformance(max(1, $iterations / 20), $bitrixAPI, $logger); // Еще меньше для загрузки файлов
        displayFileUploadResults($results['file_upload'], max(1, $iterations / 20));
        echo "\n";

        $results['json_processing'] = testJsonPerformance(max(1, $iterations / 2), $logger);
        displayJsonResults($results['json_processing'], max(1, $iterations / 2));
        break;
}

// Итоговый отчет
echo "=== ОТЧЕТ О ПРОИЗВОДИТЕЛЬНОСТИ ===\n\n";

foreach ($results as $testName => $testResults) {
    echo strtoupper(str_replace('_', ' ', $testName)) . ":\n";

    if (is_array($testResults)) {
        foreach ($testResults as $operation => $stats) {
            if (isset($stats['avg'])) {
                $operationName = str_replace('_', ' ', $operation);
                echo "  " . ucfirst($operationName) . ": {$stats['avg']} ms (avg)\n";
            }
        }
    }

    echo "\n";
}

echo "Рекомендации:\n";
echo "- Время ответа API должно быть < " . RECOMMENDED_API_RESPONSE_MS . "ms для хорошей производительности\n";
echo "- Операции с файлами могут занимать больше времени в зависимости от размера\n";
echo "- Локальные операции должны выполняться < " . RECOMMENDED_LOCAL_OP_MS . "ms\n";
echo "- JSON обработка должна быть < " . RECOMMENDED_JSON_OP_MS . "ms для больших объектов\n\n";

echo "Тестирование завершено. Проверьте логи для детальной информации.\n";

?>
