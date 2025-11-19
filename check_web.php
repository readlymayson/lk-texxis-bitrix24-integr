<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт проверки работоспособности веб-интерфейса
 */

echo "<h1>Проверка интеграции Битрикс24</h1>";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } .success { color: green; } .error { color: red; } .warning { color: orange; }</style>";

// Проверка PHP версии
echo "<h2>1. Проверка PHP</h2>";
$phpVersion = phpversion();
$phpOk = version_compare($phpVersion, '7.4.0', '>=');
echo "<p class='" . ($phpOk ? 'success' : 'error') . "'>";
echo "PHP версия: $phpVersion - " . ($phpOk ? 'OK' : 'Требуется PHP 7.4+');
echo "</p>";

// Проверка необходимых файлов
echo "<h2>2. Проверка файлов</h2>";
$requiredFiles = [
    'index.php' => 'Главная страница',
    'src/webhooks/bitrix24.php' => 'Обработчик webhook',
    'src/config/bitrix24.php' => 'Конфигурация',
    'src/classes/Logger.php' => 'Класс логирования',
    'src/classes/Bitrix24API.php' => 'API Битрикс24',
    'src/classes/LKAPI.php' => 'API личного кабинета'
];

foreach ($requiredFiles as $file => $description) {
    $exists = file_exists($file);
    echo "<p class='" . ($exists ? 'success' : 'error') . "'>";
    echo "$description ($file): " . ($exists ? 'Найден' : 'Отсутствует');
    echo "</p>";
}

// Проверка директорий
echo "<h2>3. Проверка директорий</h2>";
$requiredDirs = [
    'src/logs' => 'Директория логов',
    'src/classes' => 'Классы',
    'src/config' => 'Конфигурация',
    'src/webhooks' => 'Webhook обработчики',
    'tests' => 'Тесты'
];

foreach ($requiredDirs as $dir => $description) {
    $exists = is_dir($dir);
    $writable = $exists && is_writable($dir);
    echo "<p class='" . ($exists && $writable ? 'success' : ($exists ? 'warning' : 'error')) . "'>";
    echo "$description ($dir): " . ($exists ? ($writable ? 'OK' : 'Не доступна для записи') : 'Отсутствует');
    echo "</p>";
}

// Проверка конфигурации
echo "<h2>4. Проверка конфигурации</h2>";
$configExists = file_exists('.env') || file_exists('env.local');
echo "<p class='" . ($configExists ? 'success' : 'warning') . "'>";
echo "Файл конфигурации: " . ($configExists ? 'Найден' : 'Рекомендуется создать .env файл');
echo "</p>";

// Проверка прав доступа к webhook
$webhookFile = 'src/webhooks/bitrix24.php';
$webhookExecutable = file_exists($webhookFile) && is_executable($webhookFile);
echo "<p class='" . ($webhookExecutable ? 'success' : 'warning') . "'>";
echo "Права доступа к webhook: " . ($webhookExecutable ? 'OK' : 'Рекомендуется: chmod 755');
echo "</p>";

// Проверка тестов
echo "<h2>5. Проверка тестов</h2>";
$testFiles = [
    'tests/run_all_tests.php',
    'tests/test_integration.php',
    'tests/test_validation.php',
    'tests/test_edge_cases.php'
];

$testsExist = 0;
foreach ($testFiles as $testFile) {
    if (file_exists($testFile)) $testsExist++;
}

echo "<p class='" . ($testsExist === count($testFiles) ? 'success' : 'warning') . "'>";
echo "Файлы тестов: $testsExist/" . count($testFiles) . " найдено";
echo "</p>";

// Рекомендации
echo "<h2>6. Рекомендации</h2>";
echo "<ul>";

if (!$configExists) {
    echo "<li><span class='warning'>Создайте файл .env на основе env.example</span></li>";
}

if (!$webhookExecutable) {
    echo "<li><span class='warning'>Установите права доступа: chmod 755 src/webhooks/bitrix24.php</span></li>";
}

echo "<li><span class='success'>Откройте веб-интерфейс: index.php</span></li>";
echo "<li><span class='success'>Запустите тесты: tests/run_all_tests.php</span></li>";

echo "</ul>";

// Ссылка на веб-интерфейс
echo "<hr>";
echo "<p><a href='index.php' class='success' style='text-decoration: none; font-size: 18px;'>";
echo "🌐 Открыть веб-интерфейс интеграции";
echo "</a></p>";

?>
