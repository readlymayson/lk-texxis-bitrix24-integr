<?php

/**
 * Скрипт для тестирования загрузки различных типов файлов в Bitrix24
 *
 * Использование:
 * php test_file_upload.php [file_path]
 *
 * Примеры:
 * php test_file_upload.php ../docs/ТЗ-Личный-кабинет-TEXXIS.pdf
 * php test_file_upload.php /path/to/image.jpg
 * php test_file_upload.php /path/to/document.docx
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';

EnvLoader::load();

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);

$filePath = $argv[1] ?? null;

echo "=== ТЕСТИРОВАНИЕ ЗАГРУЗКИ ФАЙЛОВ ===\n\n";

if (empty($filePath)) {
    echo "Введите путь к файлу для тестирования:\n";
    echo "File path: ";
    $filePath = trim(fgets(STDIN));

    if (empty($filePath)) {
        echo "\nОШИБКА: Не указан путь к файлу\n";
        echo "Использование: php test_file_upload.php <file_path>\n";
        echo "Пример: php test_file_upload.php ../docs/ТЗ-Личный-кабинет-TEXXIS.pdf\n";
        exit(1);
    }
}

// Проверяем существование файла
if (!file_exists($filePath)) {
    echo "ОШИБКА: Файл не найден: {$filePath}\n";
    exit(1);
}

// Проверяем, что это файл
if (!is_file($filePath)) {
    echo "ОШИБКА: Указанный путь не является файлом: {$filePath}\n";
    exit(1);
}

// Получаем информацию о файле
$fileSize = filesize($filePath);
$fileName = basename($filePath);

// Определяем MIME тип
$mimeType = 'unknown';
$finfo = finfo_open(FILEINFO_MIME_TYPE);
if ($finfo) {
    $mimeType = finfo_file($finfo, $filePath);
    finfo_close($finfo);
}

echo "Информация о файле:\n";
echo "  Путь: {$filePath}\n";
echo "  Имя: {$fileName}\n";
echo "  Размер: {$fileSize} байт (" . round($fileSize / 1024, 2) . " KB)\n";
echo "  MIME тип: {$mimeType}\n";
echo "  Читаемый: " . (is_readable($filePath) ? 'Да' : 'Нет') . "\n\n";

echo "Загрузка файла в Bitrix24...\n";

$result = $bitrixAPI->uploadFile($filePath);

if ($result && isset($result['id'])) {
    echo "✓ УСПЕХ: Файл загружен!\n";
    echo "  ID файла: {$result['id']}\n";
    echo "  Имя файла: {$result['name']}\n";
    echo "  Внутренняя ссылка: {$result['internal_link']}\n";
    if (isset($result['disk_object_id'])) {
        echo "  ID объекта на диске: {$result['disk_object_id']}\n";
    }
    echo "\n";
} else {
    echo "✗ ОШИБКА: Не удалось загрузить файл\n";
    if (is_array($result)) {
        echo "  Результат: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "  Результат: " . var_export($result, true) . "\n";
    }
    echo "\n";
}

echo "=== ТЕСТИРОВАНИЕ ЗАВЕРШЕНО ===\n";
echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";





