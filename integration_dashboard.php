<?php
# -*- coding: utf-8 -*-

/**
 * Интерфейс интеграции Битрикс24
 */

// Обработка POST запросов для тестирования
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_webhook'])) {
    $testResult = handleTestWebhook($_POST, $logger);
}

// Обработка AJAX запросов для логов
if (isset($_GET['action']) && $_GET['action'] === 'get_log') {
    $logFile = $_GET['file'] ?? '';
    header('Content-Type: application/json');

    if (empty($logFile)) {
        echo json_encode(['error' => 'Не указан файл лога']);
        exit;
    }

    $logData = getLastLogEntries($logFile, 50);
    if (isset($logData['error'])) {
        echo json_encode(['error' => $logData['error']]);
    } else {
        echo json_encode(['entries' => $logData]);
    }
    exit;
}

/**
 * Обработка тестового webhook запроса
 */
function handleTestWebhook($postData, $logger) {
    $testEvent = $postData['test_event'] ?? '';
    $testData = $postData['test_data'] ?? '';

    if (empty($testEvent) || empty($testData)) {
        return ['success' => false, 'message' => 'Необходимо заполнить все поля'];
    }

    // Декодируем JSON
    $webhookData = json_decode($testData, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'message' => 'Некорректный JSON: ' . json_last_error_msg()];
    }

    // Имитируем обработку webhook
    $result = processTestEvent($testEvent, $webhookData, $logger);

    return $result;
}

/**
 * Имитация обработки события для тестирования
 */
function processTestEvent($eventName, $webhookData, $logger) {
    $logger->info('Test webhook processing', ['event' => $eventName]);

    // Простая валидация
    if (!isset($webhookData['event']) || $webhookData['event'] !== $eventName) {
        return ['success' => false, 'message' => 'Несоответствие события в данных'];
    }

    // Определение типа события
    $entityType = getEntityTypeFromEvent($eventName);
    if (!$entityType) {
        return ['success' => false, 'message' => 'Неизвестный тип события'];
    }

    $entityId = $webhookData['data']['FIELDS']['ID'] ?? null;
    if (!$entityId) {
        return ['success' => false, 'message' => 'Не найден ID сущности'];
    }

    return [
        'success' => true,
        'message' => 'Webhook обработан успешно',
        'details' => [
            'event' => $eventName,
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]
    ];
}

/**
 * Определение типа сущности по событию
 */
function getEntityTypeFromEvent($eventName) {
    $mapping = [
        'ONCRMCONTACTADD' => 'contact',
        'ONCRMCONTACTUPDATE' => 'contact',
        'ONCRMCONTACTDELETE' => 'contact',
        'ONCRMCOMPANYADD' => 'company',
        'ONCRMCOMPANYUPDATE' => 'company',
        'ONCRMCOMPANYDELETE' => 'company',
        'ONCRMDEALADD' => 'deal',
        'ONCRMDEALUPDATE' => 'deal',
        'ONCRM_DYNAMIC_ITEM_UPDATE' => 'smart_process'
    ];

    return $mapping[$eventName] ?? null;
}

/**
 * Получение информации о логах
 */
function getLogStats() {
    $logDir = __DIR__ . '/src/logs/';
    $stats = [];

    $logFiles = [
        'bitrix24_webhooks.log' => 'Основные логи webhook',
        'test_bitrix24_webhooks.log' => 'Логи тестирования',
        'test_validation.log' => 'Логи валидации',
        'test_edge_cases.log' => 'Логи edge cases'
    ];

    foreach ($logFiles as $file => $description) {
        $filePath = $logDir . $file;
        if (file_exists($filePath)) {
            $stats[$file] = [
                'description' => $description,
                'size' => filesize($filePath),
                'modified' => date('Y-m-d H:i:s', filemtime($filePath)),
                'exists' => true
            ];
        } else {
            $stats[$file] = [
                'description' => $description,
                'size' => 0,
                'modified' => 'Файл не найден',
                'exists' => false
            ];
        }
    }

    return $stats;
}

/**
 * Получение последних записей из лога
 */
function getLastLogEntries($logFile, $lines = 10) {
    $filePath = __DIR__ . '/src/logs/' . $logFile;

    if (!file_exists($filePath)) {
        return ['error' => 'Файл лога не найден'];
    }

    $content = file_get_contents($filePath);
    if (empty($content)) {
        return ['error' => 'Файл лога пустой'];
    }

    $linesArray = explode("\n", trim($content));
    $lastLines = array_slice($linesArray, -$lines);

    return $lastLines;
}

/**
 * Форматирование размера файла
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

$tab = $_GET['tab'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Интеграция Битрикс24 - <?php
        $titles = [
            'dashboard' => 'Дашборд',
            'logs' => 'Логи',
            'test' => 'Тестирование',
            'config' => 'Конфигурация'
        ];
        echo $titles[$tab] ?? 'Дашборд';
    ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .sidebar .nav-link {
            color: rgba(255,255,255,0.8);
            transition: all 0.3s;
        }
        .sidebar .nav-link:hover {
            color: white;
            background: rgba(255,255,255,0.1);
        }
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 8px;
        }
        .status-online { background: #28a745; }
        .status-offline { background: #dc3545; }
        .status-warning { background: #ffc107; }
        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            background: #f8f9fa;
            padding: 8px;
            margin: 4px 0;
            border-radius: 4px;
            border-left: 3px solid #007bff;
        }
        .log-entry.error { border-left-color: #dc3545; }
        .log-entry.warning { border-left-color: #ffc107; }
        .log-entry.info { border-left-color: #28a745; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0 sidebar">
                <div class="p-4">
                    <h5 class="text-white mb-4">
                        <i class="fas fa-plug me-2"></i>
                        Интеграция
                    </h5>
                    <nav class="nav flex-column">
                        <a class="nav-link <?php echo $tab === 'dashboard' ? 'active' : ''; ?>" href="?integration=1&tab=dashboard">
                            <i class="fas fa-tachometer-alt me-2"></i>Дашборд
                        </a>
                        <a class="nav-link <?php echo $tab === 'logs' ? 'active' : ''; ?>" href="?integration=1&tab=logs">
                            <i class="fas fa-file-alt me-2"></i>Логи
                        </a>
                        <a class="nav-link <?php echo $tab === 'test' ? 'active' : ''; ?>" href="?integration=1&tab=test">
                            <i class="fas fa-flask me-2"></i>Тестирование
                        </a>
                        <a class="nav-link <?php echo $tab === 'config' ? 'active' : ''; ?>" href="?integration=1&tab=config">
                            <i class="fas fa-cogs me-2"></i>Конфигурация
                        </a>
                        <hr class="my-3">
                        <a class="nav-link" href="index.php">
                            <i class="fas fa-home me-2"></i>Главная
                        </a>
                    </nav>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 px-4 py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2>
                        <?php
                        $titles = [
                            'dashboard' => '<i class="fas fa-tachometer-alt me-2"></i>Дашборд интеграции',
                            'logs' => '<i class="fas fa-file-alt me-2"></i>Просмотр логов',
                            'test' => '<i class="fas fa-flask me-2"></i>Тестирование webhook',
                            'config' => '<i class="fas fa-cogs me-2"></i>Конфигурация'
                        ];
                        echo $titles[$tab] ?? 'Дашборд';
                        ?>
                    </h2>
                    <small class="text-muted">
                        <?php echo date('d.m.Y H:i:s'); ?>
                    </small>
                </div>

                <?php if ($tab === 'dashboard'): ?>
                    <!-- Dashboard -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-server me-2"></i>Статус системы</h5>
                                </div>
                                <div class="card-body">
                                    <div class="mb-3">
                                        <span class="status-indicator status-online"></span>
                                        PHP <?php echo PHP_VERSION; ?>
                                    </div>
                                    <div class="mb-3">
                                        <span class="status-indicator status-online"></span>
                                        Веб-сервер активен
                                    </div>
                                    <div class="mb-3">
                                        <?php
                                        $configExists = file_exists(__DIR__ . '/.env');
                                        $status = $configExists ? 'status-online' : 'status-warning';
                                        $text = $configExists ? 'Конфигурация загружена' : 'Конфигурация не найдена';
                                        ?>
                                        <span class="status-indicator <?php echo $status; ?>"></span>
                                        <?php echo $text; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i>Статистика</h5>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $logStats = getLogStats();
                                    $totalSize = 0;
                                    $fileCount = 0;

                                    foreach ($logStats as $file => $info) {
                                        if ($info['exists']) {
                                            $totalSize += $info['size'];
                                            $fileCount++;
                                        }
                                    }
                                    ?>
                                    <div class="row text-center">
                                        <div class="col-6">
                                            <div class="h4 text-primary"><?php echo $fileCount; ?></div>
                                            <small class="text-muted">Файлов логов</small>
                                        </div>
                                        <div class="col-6">
                                            <div class="h4 text-success"><?php echo formatFileSize($totalSize); ?></div>
                                            <small class="text-muted">Общий размер</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>О системе</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Интеграция Битрикс24 ↔ Личный кабинет</h6>
                                            <p class="text-muted mb-2">Обработчик вебхуков для синхронизации данных между Битрикс24 и личным кабинетом клиента.</p>
                                            <p><strong>Webhook URL:</strong> <code><?php echo $_SERVER['HTTP_HOST'] ?? 'localhost'; ?>/src/webhooks/bitrix24.php</code></p>
                                            <p><strong>Личный кабинет:</strong> <a href="./" class="btn btn-sm btn-success"><i class="fas fa-user-circle me-1"></i>Открыть ЛК</a></p>
                                            <p><strong>Доступ из сети:</strong> <code>http://<?php
                                            $ip = $_SERVER['SERVER_ADDR'] ?? null;
                                            if (!$ip || $ip === '127.0.0.1') {
                                                // Попытка получить реальный IP
                                                $ip = trim(shell_exec("hostname -I | awk '{print $1}'") ?: 'YOUR_SERVER_IP');
                                            }
                                            echo $ip;
                                            ?>:<?php echo $_SERVER['SERVER_PORT'] ?? '8000'; ?>/</code></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6>Поддерживаемые события</h6>
                                            <ul class="list-unstyled">
                                                <li><i class="fas fa-check text-success me-2"></i>Изменение контактов</li>
                                                <li><i class="fas fa-check text-success me-2"></i>Создание компаний</li>
                                                <li><i class="fas fa-check text-success me-2"></i>Обновление сделок</li>
                                                <li><i class="fas fa-check text-success me-2"></i>Изменение смарт-процессов</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab === 'logs'): ?>
                    <!-- Logs Viewer -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i>Файлы логов</h5>
                                    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
                                        <i class="fas fa-sync-alt me-1"></i>Обновить
                                    </button>
                                </div>
                                <div class="card-body">
                                    <?php
                                    $logStats = getLogStats();
                                    foreach ($logStats as $file => $info):
                                    ?>
                                    <div class="d-flex justify-content-between align-items-center border-bottom py-3">
                                        <div>
                                            <h6 class="mb-1"><?php echo htmlspecialchars($info['description']); ?></h6>
                                            <small class="text-muted">
                                                <?php echo $info['exists'] ? 'Обновлен: ' . $info['modified'] : 'Файл не найден'; ?>
                                                <?php if ($info['exists']): ?>
                                                    | Размер: <?php echo formatFileSize($info['size']); ?>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                        <div>
                                            <?php if ($info['exists']): ?>
                                            <button class="btn btn-sm btn-outline-info" onclick="showLogModal('<?php echo $file; ?>', '<?php echo htmlspecialchars($info['description']); ?>')">
                                                <i class="fas fa-eye me-1"></i>Просмотр
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab === 'test'): ?>
                    <!-- Testing Form -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-flask me-2"></i>Тестирование webhook</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($testResult): ?>
                                    <div class="alert alert-<?php echo $testResult['success'] ? 'success' : 'danger'; ?> mb-4">
                                        <h6><?php echo $testResult['success'] ? 'Тест пройден успешно!' : 'Ошибка тестирования'; ?></h6>
                                        <p class="mb-0"><?php echo htmlspecialchars($testResult['message']); ?></p>
                                        <?php if (isset($testResult['details'])): ?>
                                        <small class="text-muted">
                                            Событие: <?php echo htmlspecialchars($testResult['details']['event']); ?> |
                                            Тип: <?php echo htmlspecialchars($testResult['details']['entity_type']); ?> |
                                            ID: <?php echo htmlspecialchars($testResult['details']['entity_id']); ?>
                                        </small>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>

                                    <form method="POST" class="row g-3">
                                        <div class="col-md-6">
                                            <label for="test_event" class="form-label">Тип события</label>
                                            <select class="form-select" id="test_event" name="test_event" required>
                                                <option value="">Выберите событие...</option>
                                                <option value="ONCRMCONTACTUPDATE" <?php echo (isset($_POST['test_event']) && $_POST['test_event'] === 'ONCRMCONTACTUPDATE') ? 'selected' : ''; ?>>Изменение контакта</option>
                                                <option value="ONCRMCONTACTADD" <?php echo (isset($_POST['test_event']) && $_POST['test_event'] === 'ONCRMCONTACTADD') ? 'selected' : ''; ?>>Создание контакта</option>
                                                <option value="ONCRMCOMPANYUPDATE" <?php echo (isset($_POST['test_event']) && $_POST['test_event'] === 'ONCRMCOMPANYUPDATE') ? 'selected' : ''; ?>>Изменение компании</option>
                                                <option value="ONCRMDEALUPDATE" <?php echo (isset($_POST['test_event']) && $_POST['test_event'] === 'ONCRMDEALUPDATE') ? 'selected' : ''; ?>>Изменение сделки</option>
                                                <option value="ONCRM_DYNAMIC_ITEM_UPDATE" <?php echo (isset($_POST['test_event']) && $_POST['test_event'] === 'ONCRM_DYNAMIC_ITEM_UPDATE') ? 'selected' : ''; ?>>Изменение смарт-процесса</option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <label for="test_data" class="form-label">JSON данные webhook</label>
                                            <textarea class="form-control" id="test_data" name="test_data" rows="12" placeholder='Пример:
{
  "event": "ONCRMCONTACTUPDATE",
  "data": {
    "FIELDS": {
      "ID": "12345",
      "NAME": "Иван",
      "LAST_NAME": "Петров",
      "EMAIL": [{"VALUE": "ivan@example.com"}],
      "<?php echo $config['field_mapping']['contact']['lk_client_field']; ?>": "Y"
    }
  }
}' required><?php echo isset($_POST['test_data']) ? htmlspecialchars($_POST['test_data']) : ''; ?></textarea>
                                        </div>

                                        <div class="col-12">
                                            <button type="submit" name="test_webhook" class="btn btn-primary">
                                                <i class="fas fa-play me-2"></i>Запустить тест
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary ms-2" onclick="loadExample()">
                                                <i class="fas fa-magic me-2"></i>Загрузить пример
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab === 'config'): ?>
                    <!-- Configuration -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-cogs me-2"></i>Конфигурация системы</h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6>Файлы конфигурации</h6>
                                            <div class="list-group mb-4">
                                                <?php
                                                $configFiles = [
                                                    '.env' => 'Переменные окружения',
                                                    'src/config/bitrix24.php' => 'Основная конфигурация',
                                                    'env.example' => 'Пример конфигурации'
                                                ];

                                                foreach ($configFiles as $file => $description):
                                                    $exists = file_exists(__DIR__ . '/' . $file);
                                                ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong><?php echo $file; ?></strong>
                                                        <br><small class="text-muted"><?php echo $description; ?></small>
                                                    </div>
                                                    <span class="badge bg-<?php echo $exists ? 'success' : 'warning'; ?>">
                                                        <?php echo $exists ? 'Найден' : 'Отсутствует'; ?>
                                                    </span>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <h6>Инструкция по настройке</h6>
                                            <div class="alert alert-info">
                                                <h6>1. Настройка переменных окружения</h6>
                                                <p>Создайте файл <code>.env</code> на основе <code>env.example</code></p>

                                                <h6>2. Настройка webhook в Битрикс24</h6>
                                                <p>URL: <code><?php echo $_SERVER['HTTP_HOST'] ?? 'your-domain.com'; ?>/src/webhooks/bitrix24.php</code></p>

                                                <h6>3. Проверка прав доступа</h6>
                                                <p>Убедитесь, что директория <code>src/logs/</code> доступна для записи</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Log Modal -->
    <div class="modal fade" id="logModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i><span id="logModalTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="logContent" style="max-height: 400px; overflow-y: auto;"></pre>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function showLogModal(fileName, title) {
            document.getElementById('logModalTitle').textContent = title;

            fetch('?integration=1&tab=logs&action=get_log&file=' + encodeURIComponent(fileName))
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('logContent').textContent = data.error;
                    } else {
                        document.getElementById('logContent').textContent = data.entries.join('\n');
                    }
                })
                .catch(error => {
                    document.getElementById('logContent').textContent = 'Ошибка загрузки лога: ' + error.message;
                });

            new bootstrap.Modal(document.getElementById('logModal')).show();
        }

        function loadExample() {
            const eventSelect = document.getElementById('test_event');
            const dataTextarea = document.getElementById('test_data');

            const examples = {
                'ONCRMCONTACTUPDATE': `{
  "event": "ONCRMCONTACTUPDATE",
  "data": {
    "FIELDS": {
      "ID": "12345",
      "NAME": "Иван",
      "LAST_NAME": "Петров",
      "EMAIL": [{"VALUE": "ivan.petrov@example.com", "VALUE_TYPE": "WORK"}],
      "PHONE": [{"VALUE": "+7 (999) 123-45-67", "VALUE_TYPE": "WORK"}],
      "<?php echo $config['field_mapping']['contact']['lk_client_field']; ?>": "Y"
    }
  }
}`,
                'ONCRMCONTACTADD': `{
  "event": "ONCRMCONTACTADD",
  "data": {
    "FIELDS": {
      "ID": "12346",
      "NAME": "Мария",
      "LAST_NAME": "Иванова",
      "EMAIL": [{"VALUE": "maria.ivanova@example.com", "VALUE_TYPE": "WORK"}],
      "<?php echo $config['field_mapping']['contact']['lk_client_field']; ?>": "N"
    }
  }
}`,
                'ONCRMCOMPANYUPDATE': `{
  "event": "ONCRMCOMPANYUPDATE",
  "data": {
    "FIELDS": {
      "ID": "67890",
      "TITLE": "ООО Ромашка",
      "EMAIL": [{"VALUE": "info@romashka.ru", "VALUE_TYPE": "WORK"}],
      "<?php echo $config['field_mapping']['company']['lk_client_field']; ?>": "LK-001"
    }
  }
}`
            };

            const selectedEvent = eventSelect.value;
            if (examples[selectedEvent]) {
                dataTextarea.value = examples[selectedEvent];
            }
        }
    </script>
</body>
</html>
