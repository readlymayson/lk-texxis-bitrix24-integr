<?php
/**
 * Простой веб-интерфейс для перезапуска воркера process_queue.php
 * Просто запускает restart_worker.sh скрипт
 */

// Настройки
define('PROJECT_ROOT', __DIR__);
define('SCRIPT_PATH', PROJECT_ROOT . '/src/scripts/restart_worker.sh');

// Обработка запроса
$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restart'])) {
    $output = [];
    $return_var = 0;

    // Запускаем скрипт перезапуска
    $command = 'cd ' . escapeshellarg(PROJECT_ROOT) . ' && bash ' . escapeshellarg(SCRIPT_PATH) . ' 2>&1';
    exec($command, $output, $return_var);

    $result = [
        'success' => ($return_var === 0),
        'output' => implode("\n", $output),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// HTML вывод
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Перезапуск воркера Bitrix24</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f8f9fa;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            text-align: center;
            margin-bottom: 30px;
        }
        .btn {
            background-color: #28a745;
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 18px;
            display: block;
            width: 100%;
            margin: 20px 0;
            transition: background-color 0.3s;
        }
        .btn:hover {
            background-color: #218838;
        }
        .btn:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 5px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        .result.success {
            background-color: #d4edda;
            border-color: #c3e6cb;
            color: #155724;
        }
        .result.error {
            background-color: #f8d7da;
            border-color: #f5c6cb;
            color: #721c24;
        }
        .output {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 15px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
            font-size: 14px;
        }
        .timestamp {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔄 Перезапуск воркера Bitrix24</h1>

        <form method="post">
            <button type="submit" name="restart" value="1" class="btn">
                🚀 Перезапустить воркер
            </button>
        </form>

        <?php if ($result): ?>
            <div class="result <?php echo $result['success'] ? 'success' : 'error'; ?>">
                <strong><?php echo $result['success'] ? '✅ Успешно' : '❌ Ошибка'; ?></strong>
                <div class="timestamp"><?php echo $result['timestamp']; ?></div>
                <div class="output"><?php echo htmlspecialchars($result['output']); ?></div>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 30px; color: #6c757d; font-size: 14px;">
            <p>Этот скрипт запускает <code>restart_worker.sh</code> для перезапуска воркера обработки очереди Bitrix24.</p>
        </div>
    </div>
</body>
</html>