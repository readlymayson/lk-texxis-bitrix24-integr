<?php
require_once __DIR__ . '/src/config/bitrix24.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';
require_once __DIR__ . '/src/classes/Logger.php';

$config = require_once __DIR__ . '/src/config/bitrix24.php';
$logger = new Logger($config['logging']);
$bitrixAPI = new Bitrix24API($config['bitrix24'], $logger);
$localStorage = new LocalStorage($config, $logger);

// Получить данные проекта из Bitrix24
$projectData = $bitrixAPI->getEntityData('smart_process', 2);

if ($projectData && isset($projectData['result']['item'])) {
    $processData = $projectData['result']['item'];

    echo "=== ТЕСТИРОВАНИЕ handleSmartProcessUpdate ===\n";

    // Вызвать функцию handleSmartProcessUpdate
    require_once __DIR__ . '/src/webhooks/bitrix24.php';
    $result = handleSmartProcessUpdate($processData, $localStorage, $bitrixAPI, $logger, $config);

    echo "Результат: " . ($result ? 'УСПЕХ' : 'ОШИБКА') . "\n";

    // Проверить, что сохранилось
    $savedProject = $localStorage->getProject(2);
    echo "Сохраненный проект: " . (empty($savedProject) ? 'ПУСТОЙ' : 'НАЙДЕН') . "\n";
    if ($savedProject) {
        echo "Client ID: " . ($savedProject['client_id'] ?? 'NULL') . "\n";
        echo "Organization: " . ($savedProject['organization_name'] ?? 'EMPTY') . "\n";
    }
} else {
    echo "Не удалось получить данные проекта\n";
}
?>
