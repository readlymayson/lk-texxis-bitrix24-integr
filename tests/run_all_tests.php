<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для запуска всех тестов интеграции Битрикс24
 */

echo "========================================\n";
echo "ЗАПУСК ПОЛНОГО ТЕСТИРОВАНИЯ\n";
echo "Интеграция Битрикс24 с ЛК\n";
echo "========================================\n\n";

$testFiles = [
    'test_integration.php' => 'Основные тесты интеграции',
    'test_validation.php' => 'Тесты валидации webhook',
    'test_edge_cases.php' => 'Тесты edge cases и ошибок'
];

$results = [];
$totalTests = 0;
$totalPassed = 0;

foreach ($testFiles as $file => $description) {
    echo "ЗАПУСК: {$description}\n";
    echo str_repeat("-", 40) . "\n";

    $output = shell_exec("php {$file} 2>&1");

    // Парсим результаты из вывода
    if (preg_match('/ПРОЙДЕНО: (\d+)\/(\d+)/', $output, $matches)) {
        $passed = (int) $matches[1];
        $total = (int) $matches[2];

        $results[$file] = [
            'description' => $description,
            'passed' => $passed,
            'total' => $total,
            'success' => ($passed === $total)
        ];

        $totalTests += $total;
        $totalPassed += $passed;

        echo "✓ Завершено: {$passed}/{$total} тестов пройдено\n\n";
    } else {
        echo "✗ Ошибка выполнения теста\n\n";
        $results[$file] = [
            'description' => $description,
            'passed' => 0,
            'total' => 0,
            'success' => false
        ];
    }
}

echo "========================================\n";
echo "ИТОГОВЫЙ ОТЧЕТ ПО ТЕСТИРОВАНИЮ\n";
echo "========================================\n\n";

echo sprintf("%-30s | %-5s | %-5s | %-7s\n", "Тест", "Всего", "Пройд", "Статус");
echo str_repeat("-", 55) . "\n";

foreach ($results as $file => $result) {
    $status = $result['success'] ? "✓" : "✗";
    echo sprintf("%-30s | %-5d | %-5d | %-7s\n",
        $result['description'],
        $result['total'],
        $result['passed'],
        $status
    );
}

echo str_repeat("-", 55) . "\n";
echo sprintf("%-30s | %-5d | %-5d | %-7s\n",
    "ИТОГО",
    $totalTests,
    $totalPassed,
    ($totalPassed === $totalTests ? "✓" : "✗")
);

echo "\n========================================\n";

if ($totalPassed === $totalTests) {
    echo "🎉 ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n";
    echo "Проект готов к использованию.\n";
} else {
    echo "⚠️ НЕКОТОРЫЕ ТЕСТЫ ПРОВАЛЕНЫ\n";
    echo "Проверьте логи для получения подробной информации.\n";
}

echo "\nЛоги тестов:\n";
echo "- src/logs/test_bitrix24_webhooks.log\n";
echo "- src/logs/test_validation.log\n";
echo "- src/logs/test_edge_cases.log\n";

echo "\n========================================\n";

?>
