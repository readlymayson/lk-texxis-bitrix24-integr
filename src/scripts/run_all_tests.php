<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для запуска всех тестов интеграции с Битрикс24
 *
 * Запускает все тестовые скрипты из директории scripts в правильном порядке
 *
 * Использование:
 * php run_all_tests.php [test_suite]
 *
 * Примеры:
 * php run_all_tests.php all           # Запустить все тесты
 * php run_all_tests.php basic         # Запустить базовые тесты (webhook, sync, project)
 * php run_all_tests.php security      # Запустить тесты безопасности
 * php run_all_tests.php performance   # Запустить тесты производительности
 */

$testSuite = $argv[1] ?? 'all';

echo "=== ЗАПУСК ВСЕХ ТЕСТОВ ИНТЕГРАЦИИ BITRIX24 ===\n\n";

// Определяем порядок выполнения тестов
$testGroups = [
    'basic' => [
        'test_webhook.php' => 'Тестирование webhook endpoint',
        'test_sync.php' => 'Тестирование синхронизации данных',
        'test_project_creation.php' => 'Тестирование создания проектов',
        'test_project_deletion.php' => 'Тестирование удаления проектов',
        'test_smart_process_cards.php' => 'Тестирование смарт-процессов',
        'test_smart_process_mapping.php' => 'Тестирование маппинга полей',
        'test_uf_codes_validation.php' => 'Тестирование валидации UF кодов',
    ],

    'file_operations' => [
        'test_file_upload.php' => 'Тестирование загрузки файлов',
    ],

    'error_handling' => [
        'test_error_handling.php' => 'Тестирование обработки ошибок',
    ],

    'security' => [
        'test_security.php' => 'Тестирование безопасности',
    ],

    'performance' => [
        'test_performance.php' => 'Тестирование производительности',
    ],

    'email' => [
        'test_send_email.php' => 'Тестирование отправки email',
    ]
];

// Определяем какие группы запускать
$groupsToRun = [];

switch ($testSuite) {
    case 'basic':
        $groupsToRun = ['basic'];
        break;

    case 'security':
        $groupsToRun = ['security'];
        break;

    case 'performance':
        $groupsToRun = ['performance'];
        break;

    case 'errors':
        $groupsToRun = ['error_handling'];
        break;

    case 'files':
        $groupsToRun = ['file_operations'];
        break;

    case 'email':
        $groupsToRun = ['email'];
        break;

    case 'all':
    default:
        $groupsToRun = ['basic', 'file_operations', 'error_handling', 'security', 'performance', 'email'];
        break;
}

$overallResults = [
    'total_tests' => 0,
    'passed' => 0,
    'failed' => 0,
    'skipped' => 0,
    'errors' => []
];

echo "Запускаемые группы тестов: " . implode(', ', $groupsToRun) . "\n\n";

// Запускаем тесты
foreach ($groupsToRun as $groupName) {
    if (!isset($testGroups[$groupName])) {
        echo "⚠️  Группа тестов '{$groupName}' не найдена, пропускаем\n\n";
        continue;
    }

    echo "=== ЗАПУСК ГРУППЫ: " . strtoupper($groupName) . " ===\n\n";

    foreach ($testGroups[$groupName] as $testFile => $description) {
        $testPath = __DIR__ . '/' . $testFile;

        if (!file_exists($testPath)) {
            echo "⚠️  Тест {$testFile} не найден, пропускаем\n";
            $overallResults['skipped']++;
            continue;
        }

        echo "Запуск: {$description}\n";
        echo "Файл: {$testFile}\n";

        $startTime = microtime(true);

        // Запускаем тест
        $command = "php {$testPath}";
        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        $endTime = microtime(true);
        $duration = round($endTime - $startTime, 2);

        echo "Время выполнения: {$duration} сек\n";

        if ($returnCode === 0) {
            echo "✓ ПРОЙДЕН\n";
            $overallResults['passed']++;
        } else {
            echo "✗ ПРОВАЛЕН (код возврата: {$returnCode})\n";
            $overallResults['failed']++;

            // Сохраняем информацию об ошибке
            $overallResults['errors'][] = [
                'test' => $testFile,
                'description' => $description,
                'return_code' => $returnCode,
                'output' => array_slice($output, -10) // Последние 10 строк вывода
            ];
        }

        echo "Вывод теста:\n";
        foreach ($output as $line) {
            echo "  {$line}\n";
        }

        echo "\n" . str_repeat("-", 60) . "\n\n";

        $overallResults['total_tests']++;

        // Небольшая пауза между тестами
        sleep(1);
    }

    echo "\n";
}

// Итоговый отчет
echo str_repeat("=", 80) . "\n";
echo "ОБЩИЙ ОТЧЕТ О ТЕСТИРОВАНИИ\n";
echo str_repeat("=", 80) . "\n\n";

echo "Группы тестов: " . implode(', ', $groupsToRun) . "\n";
echo "Всего тестов: {$overallResults['total_tests']}\n";
echo "Прошло успешно: {$overallResults['passed']}\n";
echo "Провалено: {$overallResults['failed']}\n";
echo "Пропущено: {$overallResults['skipped']}\n";

if ($overallResults['total_tests'] > 0) {
    $successRate = round(($overallResults['passed'] / $overallResults['total_tests']) * 100, 1);
    echo "Успешность: {$successRate}%\n";
}

echo "\n";

if (!empty($overallResults['errors'])) {
    echo "ПОДРОБНОСТИ ОБ ОШИБКАХ:\n";
    echo str_repeat("-", 40) . "\n";

    foreach ($overallResults['errors'] as $error) {
        echo "Тест: {$error['test']}\n";
        echo "Описание: {$error['description']}\n";
        echo "Код возврата: {$error['return_code']}\n";

        if (!empty($error['output'])) {
            echo "Последний вывод:\n";
            foreach ($error['output'] as $line) {
                echo "  {$line}\n";
            }
        }

        echo "\n";
    }
}

echo str_repeat("=", 80) . "\n";

if ($overallResults['failed'] === 0 && $overallResults['skipped'] === 0) {
    echo "🎉 ВСЕ ТЕСТЫ ПРОШЛИ УСПЕШНО!\n";
} elseif ($overallResults['failed'] === 0) {
    echo "✅ ОСНОВНЫЕ ТЕСТЫ ПРОШЛИ УСПЕШНО (с пропусками)\n";
} else {
    echo "❌ ОБНАРУЖЕНЫ ОШИБКИ В ТЕСТАХ\n";
    echo "Рекомендуется исправить ошибки перед запуском в production\n";
}

echo "\nПроверьте логи в файле: src/logs/bitrix24_webhooks.log\n";

echo str_repeat("=", 80) . "\n";
echo "ЗАВЕРШЕНИЕ ТЕСТИРОВАНИЯ\n";
echo str_repeat("=", 80) . "\n";

?>


