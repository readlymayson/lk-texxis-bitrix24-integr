<?php
# -*- coding: utf-8 -*-

/**
 * Простой тест webhook через браузер
 * 
 * Откройте в браузере: https://efrolov-dev.ru/application/lk/src/scripts/test_webhook_simple.php
 * 
 * Или передайте параметры:
 * ?event=ONCRMCONTACTUPDATE&entity_id=2
 */

header('Content-Type: text/html; charset=utf-8');

$webhookUrl = 'https://efrolov-dev.ru/application/lk/src/webhooks/bitrix24.php';
$eventType = $_GET['event'] ?? 'ONCRMCONTACTUPDATE';
$entityId = $_GET['entity_id'] ?? '2';
$entityTypeId = $_GET['entity_type_id'] ?? null;

// Загружаем конфиг для получения application_token
require_once __DIR__ . '/../classes/EnvLoader.php';
EnvLoader::load();
$applicationToken = getenv('BITRIX24_APPLICATION_TOKEN') ?: '';

// Формируем тестовые данные
$testData = [
    'event' => $eventType,
    'event_handler_id' => '999',
    'data' => [
        'FIELDS' => [
            'ID' => $entityId
        ]
    ],
    'ts' => time(),
    'auth' => [
        'domain' => 'b24-11ue58.bitrix24.ru',
        'client_endpoint' => 'https://b24-11ue58.bitrix24.ru/rest/',
        'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
        'member_id' => '42d6c4c35f73b1c45de11528bd16c826',
    ]
];

if (!empty($applicationToken)) {
    $testData['auth']['application_token'] = $applicationToken;
}

if (str_contains($eventType, 'DYNAMICITEM') || str_contains($eventType, 'DYNAMIC')) {
    if ($entityTypeId) {
        $testData['data']['FIELDS']['ENTITY_TYPE_ID'] = $entityTypeId;
    } else {
        require_once __DIR__ . '/../config/bitrix24.php';
        $config = require_once __DIR__ . '/../config/bitrix24.php';
        $defaultEntityTypeId = $config['bitrix24']['smart_process_id'] ?? '1038';
        $testData['data']['FIELDS']['ENTITY_TYPE_ID'] = $defaultEntityTypeId;
    }
}

$postData = http_build_query($testData);

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тест Webhook Endpoint</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        button:hover { background: #45a049; }
        .result { margin-top: 20px; padding: 15px; border-radius: 4px; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .params { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Тест Webhook Endpoint</h1>
        
        <form method="GET">
            <div class="params">
                <div class="form-group">
                    <label for="event">Тип события:</label>
                    <select name="event" id="event">
                        <option value="ONCRMCONTACTUPDATE" <?= $eventType === 'ONCRMCONTACTUPDATE' ? 'selected' : '' ?>>ONCRMCONTACTUPDATE</option>
                        <option value="ONCRMCONTACTADD" <?= $eventType === 'ONCRMCONTACTADD' ? 'selected' : '' ?>>ONCRMCONTACTADD</option>
                        <option value="ONCRMCOMPANYUPDATE" <?= $eventType === 'ONCRMCOMPANYUPDATE' ? 'selected' : '' ?>>ONCRMCOMPANYUPDATE</option>
                        <option value="ONCRMCOMPANYADD" <?= $eventType === 'ONCRMCOMPANYADD' ? 'selected' : '' ?>>ONCRMCOMPANYADD</option>
                        <option value="ONCRMDYNAMICITEMUPDATE" <?= $eventType === 'ONCRMDYNAMICITEMUPDATE' ? 'selected' : '' ?>>ONCRMDYNAMICITEMUPDATE</option>
                        <option value="ONCRMDYNAMICITEMADD" <?= $eventType === 'ONCRMDYNAMICITEMADD' ? 'selected' : '' ?>>ONCRMDYNAMICITEMADD</option>
                        <option value="ONCRMDYNAMICITEMDELETE" <?= $eventType === 'ONCRMDYNAMICITEMDELETE' ? 'selected' : '' ?>>ONCRMDYNAMICITEMDELETE</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="entity_id">Entity ID:</label>
                    <input type="text" name="entity_id" id="entity_id" value="<?= htmlspecialchars($entityId) ?>" required>
                </div>
            </div>
            
            <?php if (str_contains($eventType, 'DYNAMICITEM') || str_contains($eventType, 'DYNAMIC')): ?>
            <div class="form-group">
                <label for="entity_type_id">Entity Type ID (для смарт-процессов):</label>
                <input type="text" name="entity_type_id" id="entity_type_id" value="<?= htmlspecialchars($entityTypeId ?? '1038') ?>" placeholder="1038">
            </div>
            <?php endif; ?>
            
            <button type="submit">Отправить тестовый запрос</button>
        </form>
        
        <?php if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['event'])): ?>
        <div class="result info">
            <h3>📋 Параметры запроса:</h3>
            <pre><?= htmlspecialchars(json_encode($testData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        </div>
        
        <div class="result">
            <h3>📤 Отправка запроса...</h3>
            <?php
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: Bitrix24 Webhook Engine',
                    'Content-Length: ' . strlen($postData)
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT => 30
            ]);
            
            $startTime = microtime(true);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            curl_close($ch);
            
            if ($error) {
                echo '<div class="error"><strong>✗ Ошибка CURL:</strong><br>' . htmlspecialchars($error) . '</div>';
            } else {
                echo '<div class="info">';
                echo '<strong>HTTP код:</strong> ' . $httpCode . '<br>';
                echo '<strong>Время ответа:</strong> ' . $totalTime . ' мс<br>';
                echo '</div>';
                
                if ($httpCode === 200) {
                    echo '<div class="success"><strong>✓ Успех!</strong> Запрос принят и обработан.</div>';
                } elseif ($httpCode === 400) {
                    echo '<div class="error"><strong>⚠ Ошибка валидации (400)</strong><br>Запрос отклонен на этапе валидации. Проверьте логи.</div>';
                } elseif ($httpCode === 500) {
                    echo '<div class="error"><strong>✗ Ошибка сервера (500)</strong><br>Внутренняя ошибка при обработке. Проверьте логи.</div>';
                } else {
                    echo '<div class="error"><strong>⚠ Неожиданный код:</strong> ' . $httpCode . '</div>';
                }
                
                echo '<h4>Ответ сервера:</h4>';
                $responseData = json_decode($response, true);
                if ($responseData !== null) {
                    echo '<pre>' . htmlspecialchars(json_encode($responseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
                } else {
                    echo '<pre>' . htmlspecialchars($response) . '</pre>';
                }
            }
            ?>
        </div>
        
        <div class="result info">
            <h3>📝 Рекомендации:</h3>
            <ul>
                <li>Проверьте файл логов: <code>src/logs/bitrix24_webhooks.log</code></li>
                <li>Убедитесь, что endpoint доступен: <code><?= $webhookUrl ?></code></li>
                <li>Проверьте настройки <code>BITRIX24_APPLICATION_TOKEN</code> в .env файле</li>
                <li>Проверьте права доступа к директории логов</li>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>

