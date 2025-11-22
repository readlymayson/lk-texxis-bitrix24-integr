<?php
$config = require_once __DIR__ . '/src/config/bitrix24.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/classes/Logger.php';

echo "Config loaded: " . (isset($config['bitrix24']) ? 'YES' : 'NO') . "\n";
echo "Bitrix24 keys: " . (isset($config['bitrix24']) ? implode(', ', array_keys($config['bitrix24'])) : 'N/A') . "\n";

$logger = new Logger($config['logging']);
$bitrixAPI = new Bitrix24API($config, $logger);

// Получить данные проекта из Bitrix24
$projectData = $bitrixAPI->getEntityData('smart_process', 2);

if ($projectData && isset($projectData['result']['item'])) {
    $project = $projectData['result']['item'];

    echo "=== ДАННЫЕ ПРОЕКТА ИЗ BITRIX24 ===\n";
    echo "Ключи: " . implode(', ', array_keys($project)) . "\n";
    echo "ID: " . ($project['id'] ?? 'НЕТ') . "\n";
    echo "Title: " . ($project['title'] ?? 'НЕТ') . "\n";
    echo "ContactId: " . ($project['contactId'] ?? 'НЕТ') . "\n";
    echo "StageId: " . ($project['stageId'] ?? 'НЕТ') . "\n";

    // Маппинг - создаем reflection для доступа к protected методу
    require_once __DIR__ . '/src/classes/LKAPI.php';
    $lkApi = new LKAPI($config, $logger);
    $reflection = new ReflectionClass($lkApi);
    $method = $reflection->getMethod('mapProjectFields');
    $method->setAccessible(true);
    $mapped = $method->invoke($lkApi, $project);

    echo "\n=== РЕЗУЛЬТАТ МАППИНГА ===\n";
    foreach ($mapped as $key => $value) {
        echo "$key: " . (is_null($value) ? 'NULL' : $value) . "\n";
    }
} else {
    echo "Не удалось получить данные проекта\n";
}
?>
