<?php

/**
 * Обработчик очереди вебхуков Bitrix24
 *
 * Запускается как фоновый процесс для последовательной обработки событий.
 * Гарантирует порядок обработки событий для каждого entity_id.
 */

// Устанавливаем часовой пояс для корректного отображения времени в логах
date_default_timezone_set('Europe/Moscow');

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';
require_once __DIR__ . '/../classes/QueueManager.php';
require_once __DIR__ . '/../classes/WebhookProcessor.php';

// Проверка доступности необходимых файлов
$configFile = __DIR__ . '/../config/bitrix24.php';
if (!file_exists($configFile)) {
    error_log('ERROR: Config file not found: ' . $configFile);
    exit(1);
}

$config = require_once $configFile;

if (empty($config)) {
    error_log('ERROR: Failed to load configuration');
    exit(1);
}

try {
    $logger = new Logger($config);
    $bitrixAPI = new Bitrix24API($config, $logger);
    $config['reconnect']=true;
    $localStorage = new LocalStorage($logger, $config);
    $queueManager = new QueueManager($logger, $config);
    $processor = new WebhookProcessor($bitrixAPI, $localStorage, $logger, $config);
} catch (Exception $e) {
    error_log('ERROR: Failed to initialize classes: ' . $e->getMessage());
    exit(1);
}

/**
 * Проверка и установка PID файла для предотвращения запуска нескольких экземпляров
 */
function checkAndSetPidFile($logger)
{
    $pidFile = __DIR__ . '/../data/worker.pid';

    // Проверяем, существует ли уже PID файл
    if (file_exists($pidFile)) {
        $existingPid = trim(file_get_contents($pidFile));

        // Проверяем, работает ли процесс с этим PID
        if ($existingPid && posix_kill($existingPid, 0)) {
            $logger->warning('Worker is already running', ['existing_pid' => $existingPid]);
            return false;
        } else {
            $logger->info('Removing stale PID file', ['old_pid' => $existingPid]);
            unlink($pidFile);
        }
    }

    // Записываем текущий PID
    $currentPid = getmypid();
    if (file_put_contents($pidFile, $currentPid) === false) {
        $logger->warning('Failed to write PID file - worker will continue without PID file protection');
        // Продолжаем работу без PID файла, но выводим предупреждение
    } else {
        $logger->info('Worker PID file created', ['pid' => $currentPid]);
    }

    return true;
}

/**
 * Удаление PID файла при завершении
 */
function removePidFile($logger)
{
    $pidFile = __DIR__ . '/../data/worker.pid';

    if (file_exists($pidFile)) {
        unlink($pidFile);
        $logger->info('Worker PID file removed');
    }
}

/**
 * Основная функция обработки очереди
 */
function processQueue($queueManager, $processor, $logger, $config)
{
    $batchSize = $config['queue']['batch_size'] ?? 10;
    $maxAttempts = $config['queue']['max_attempts'] ?? 3;

    $logger->info('Starting queue processing', [
        'batch_size' => $batchSize,
        'max_attempts' => $maxAttempts
    ]);

    $processedCount = 0;
    $failedCount = 0;
    $skippedCount = 0;

    // Получаем задачи из очереди
    $tasks = $queueManager->popBatch($batchSize);

    if (empty($tasks)) {
        $logger->debug('No pending tasks found');
        return ['processed' => 0, 'failed' => 0, 'skipped' => 0];
    }

    $logger->info('Retrieved tasks from queue', ['task_count' => count($tasks)]);

    // Группируем задачи по entity_id для последовательной обработки
    $groupedTasks = $queueManager->groupByEntity($tasks);

    $logger->info('Grouped tasks by entity', [
        'entity_count' => count($groupedTasks),
        'entities' => array_keys($groupedTasks)
    ]);

    foreach ($groupedTasks as $entityId => $entityTasks) {
        $logger->info('Processing entity tasks', [
            'entity_id' => $entityId,
            'task_count' => count($entityTasks)
        ]);

        // Сортируем задачи по времени для гарантии порядка
        usort($entityTasks, function($a, $b) {
            return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
        });

        foreach ($entityTasks as $task) {
            $taskId = $task['id'];
            $eventName = $task['event'];
            $webhookData = $task['data'];
            $attempts = $task['attempts'] ?? 0;

            $logger->debug('Processing task', [
                'task_id' => $taskId,
                'entity_id' => $entityId,
                'event' => $eventName,
                'attempts' => $attempts
            ]);

            try {
                // Обрабатываем событие через WebhookProcessor
                $result = $processor->processEvent($eventName, $webhookData);

                if ($result) {
                    // Успешно обработано - удаляем из очереди
                    $queueManager->updateStatus($taskId, 'completed');
                    $processedCount++;
                    $logger->info('Task processed successfully', [
                        'task_id' => $taskId,
                        'entity_id' => $entityId,
                        'event' => $eventName
                    ]);
                } else {
                    // Обработка не удалась
                    $attempts++;

                    if ($attempts >= $maxAttempts) {
                        // Превышено максимальное количество попыток
                        $queueManager->updateStatus($taskId, 'failed', 'Max attempts exceeded');
                        $failedCount++;
                        $logger->error('Task failed permanently', [
                            'task_id' => $taskId,
                            'entity_id' => $entityId,
                            'event' => $eventName,
                            'attempts' => $attempts
                        ]);
                    } else {
                        // Будет повторена позже
                        $queueManager->updateStatus($taskId, 'pending');
                        $skippedCount++;
                        $logger->warning('Task processing failed, will retry', [
                            'task_id' => $taskId,
                            'entity_id' => $entityId,
                            'event' => $eventName,
                            'attempts' => $attempts,
                            'max_attempts' => $maxAttempts
                        ]);
                    }
                }

            } catch (Exception $e) {
                $errorMessage = $e->getMessage();
                $attempts++;

                $logger->error('Exception during task processing', [
                    'task_id' => $taskId,
                    'entity_id' => $entityId,
                    'event' => $eventName,
                    'attempts' => $attempts,
                    'error' => $errorMessage
                ]);

                if ($attempts >= $maxAttempts) {
                    $queueManager->updateStatus($taskId, 'failed', $errorMessage);
                    $failedCount++;
                } else {
                    $queueManager->updateStatus($taskId, 'pending');
                    $skippedCount++;
                }
            }
        }
    }

    // Очищаем завершенные задачи из файла
    $cleanedCount = $queueManager->clearProcessed();

    $logger->info('Queue processing completed', [
        'processed' => $processedCount,
        'failed' => $failedCount,
        'skipped' => $skippedCount,
        'cleaned' => $cleanedCount
    ]);

    return [
        'processed' => $processedCount,
        'failed' => $failedCount,
        'skipped' => $skippedCount,
        'cleaned' => $cleanedCount
    ];
}

// Основной цикл работы воркера
function main($queueManager, $processor, $logger, $config)
{
    $logger->info('=== WORKER STARTED ===', [
        'timestamp' => date('Y-m-d H:i:s'),
        'pid' => getmypid()
    ]);

    // Устанавливаем обработчики сигналов для корректного завершения
    pcntl_signal(SIGTERM, function() use ($logger) {
        $logger->info('Received SIGTERM, shutting down gracefully');
        removePidFile($logger);
        exit(0);
    });

    pcntl_signal(SIGINT, function() use ($logger) {
        $logger->info('Received SIGINT, shutting down gracefully');
        removePidFile($logger);
        exit(0);
    });

    // Проверяем и устанавливаем PID файл
    if (!checkAndSetPidFile($logger)) {
        exit(1);
    }

    $iteration = 0;
    $totalProcessed = 0;
    $totalFailed = 0;

    try {
        while (true) {
            $iteration++;

            $logger->debug('Worker iteration', ['iteration' => $iteration]);

            $stats = processQueue($queueManager, $processor, $logger, $config);

            $totalProcessed += $stats['processed'];
            $totalFailed += $stats['failed'];

            // Если в этой итерации ничего не обработали, делаем паузу
            if ($stats['processed'] === 0 && $stats['failed'] === 0 && $stats['skipped'] === 0) {
                $sleepTime = $config['queue']['idle_sleep_time'] ?? 30;
                $logger->debug('No tasks processed, sleeping', ['sleep_seconds' => $sleepTime]);
                sleep($sleepTime);
            } else {
                // Если были задачи, проверяем сразу еще раз
                usleep(100000); // 0.1 секунды
            }

            // Обработка сигналов
            pcntl_signal_dispatch();
        }

    } catch (Exception $e) {
        $logger->error('Fatal error in worker main loop', [
            'error' => $e->getMessage(),
            'iteration' => $iteration,
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed
        ]);

    } finally {
        removePidFile($logger);

        $logger->info('=== WORKER STOPPED ===', [
            'total_iterations' => $iteration,
            'total_processed' => $totalProcessed,
            'total_failed' => $totalFailed,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    }
}

// Запуск воркера
main($queueManager, $processor, $logger, $config);