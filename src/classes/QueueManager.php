<?php

/**
 * Менеджер очереди для обработки вебхуков Bitrix24
 * Обеспечивает последовательную обработку событий по сущностям
 */
class QueueManager
{
    private $queueFile;
    private $logger;
    private $config;

    public function __construct($logger, $config = null)
    {
        $this->logger = $logger;
        $this->config = $config;
        $this->queueFile = __DIR__ . '/../data/webhook_queue.json';

        // Создаем директорию если не существует
        $queueDir = dirname($this->queueFile);
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0755, true);
        }
    }

    /**
     * Добавляет новое событие в очередь
     *
     * @param array $webhookData Данные вебхука от Bitrix24
     * @return string|false ID добавленной задачи или false при ошибке
     */
    public function push(array $webhookData)
    {
        $taskId = $this->generateTaskId();
        $entityId = $this->extractEntityId($webhookData);
        $entityType = $this->extractEntityType($webhookData['event'] ?? '');

        $task = [
            'id' => $taskId,
            'event' => $webhookData['event'] ?? '',
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'data' => $webhookData,
            'status' => 'pending',
            'attempts' => 0,
            'ts' => time(),
            'created_at' => date('Y-m-d H:i:s')
        ];

        $this->logger->debug('Adding task to queue', [
            'task_id' => $taskId,
            'entity_id' => $entityId,
            'event' => $task['event']
        ]);

        return $this->atomicWrite(function(&$queue) use ($task) {
            $queue[] = $task;
            return $task['id'];
        });
    }

    /**
     * Получает порцию необработанных задач
     *
     * @param int $limit Максимальное количество задач
     * @return array Массив задач
     */
    public function popBatch($limit = 10)
    {
        return $this->atomicWrite(function(&$queue) use ($limit) {
            $pendingTasks = [];
            $remainingTasks = [];

            foreach ($queue as &$task) {
                if (!is_array($task)) {
                    continue; // Пропускаем невалидные задачи
                }

                if (count($pendingTasks) < $limit && ($task['status'] ?? 'pending') === 'pending') {
                    $task['status'] = 'processing';
                    $task['processed_at'] = date('Y-m-d H:i:s');
                    $pendingTasks[] = $task;
                } else {
                    $remainingTasks[] = $task;
                }
            }

            // Обновляем очередь, оставляя только необработанные задачи
            $queue = $remainingTasks;

            // Возвращаем взятые задачи
            return $pendingTasks;
        });
    }

    /**
     * Обновляет статус задачи
     *
     * @param string $taskId ID задачи
     * @param string $status Новый статус (pending, processing, completed, failed)
     * @param string|null $error Сообщение об ошибке (для failed статуса)
     * @return bool
     */
    public function updateStatus($taskId, $status, $error = null)
    {
        return $this->atomicWrite(function(&$queue) use ($taskId, $status, $error) {
            foreach ($queue as &$task) {
                if ($task['id'] === $taskId) {
                    $task['status'] = $status;
                    $task['updated_at'] = date('Y-m-d H:i:s');

                    if ($status === 'failed' && $error) {
                        $task['last_error'] = $error;
                        $task['attempts'] = ($task['attempts'] ?? 0) + 1;
                    } elseif ($status === 'completed') {
                        $task['completed_at'] = date('Y-m-d H:i:s');
                    }

                    $this->logger->debug('Task status updated', [
                        'task_id' => $taskId,
                        'status' => $status,
                        'attempts' => $task['attempts'] ?? 0
                    ]);

                    return true;
                }
            }
            return false;
        });
    }

    /**
     * Удаляет успешно завершенные задачи
     *
     * @return int Количество удаленных задач
     */
    public function clearProcessed()
    {
        return $this->atomicWrite(function(&$queue) {
            $originalCount = count($queue);
            $queue = array_filter($queue, function($task) {
                return $task['status'] !== 'completed';
            });
            $removedCount = $originalCount - count($queue);

            if ($removedCount > 0) {
                $this->logger->info('Cleared processed tasks', ['removed_count' => $removedCount]);
            }

            return $removedCount;
        });
    }

    /**
     * Получает статистику очереди
     *
     * @return array Статистика по статусам
     */
    public function getStats()
    {
        $queue = $this->readQueue();
        if (!is_array($queue)) {
            $queue = [];
        }

        $stats = [
            'total' => count($queue),
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];

        foreach ($queue as $task) {
            $status = $task['status'] ?? 'unknown';
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    /**
     * Группирует задачи по entity_id для последовательной обработки
     *
     * @param array $tasks Массив задач
     * @return array Группированные задачи
     */
    public function groupByEntity(array $tasks)
    {
        $grouped = [];

        foreach ($tasks as $task) {
            $entityId = $task['entity_id'] ?? 'unknown';
            if (!isset($grouped[$entityId])) {
                $grouped[$entityId] = [];
            }
            $grouped[$entityId][] = $task;
        }

        // Сортируем каждую группу по времени создания
        foreach ($grouped as $entityId => &$entityTasks) {
            usort($entityTasks, function($a, $b) {
                return ($a['ts'] ?? 0) <=> ($b['ts'] ?? 0);
            });
        }

        return $grouped;
    }

    /**
     * Атомарная запись в файл с использованием flock
     *
     * @param callable $operation Функция, которая принимает текущую очередь по ссылке и возвращает результат
     * @return mixed Результат выполнения операции
     */
    private function atomicWrite(callable $operation)
    {
        $fp = fopen($this->queueFile, 'c+');
        if (!$fp) {
            $this->logger->error('Failed to open queue file', ['file' => $this->queueFile]);
            return false;
        }

        // Блокируем файл для эксклюзивного доступа
        if (!flock($fp, LOCK_EX)) {
            $this->logger->error('Failed to acquire file lock');
            fclose($fp);
            return false;
        }

        try {
            // Читаем текущую очередь
            $queue = [];
            $content = '';
            while (!feof($fp)) {
                $content .= fread($fp, 8192);
            }

            if (!empty($content)) {
                $queue = json_decode($content, true);
                if ($queue === null) {
                    $this->logger->warning('Invalid JSON in queue file, resetting', [
                        'file' => $this->queueFile,
                        'json_error' => json_last_error_msg()
                    ]);
                    $queue = [];
                }
            }

            // Выполняем операцию (передаем очередь по ссылке)
            $result = $operation($queue);

            // Записываем обновленную очередь
            ftruncate($fp, 0);
            rewind($fp);
            $jsonData = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($jsonData === false) {
                $this->logger->error('Failed to encode queue to JSON', [
                    'json_error' => json_last_error_msg()
                ]);
                return false;
            }

            $bytesWritten = fwrite($fp, $jsonData);
            if ($bytesWritten === false) {
                $this->logger->error('Failed to write to queue file');
                return false;
            }

            $this->logger->debug('Successfully wrote to queue file', [
                'bytes_written' => $bytesWritten,
                'tasks_count' => count($queue)
            ]);

            return $result;

        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Читает очередь без блокировки (только для чтения)
     *
     * @return array Текущая очередь
     */
    private function readQueue()
    {
        if (!file_exists($this->queueFile)) {
            return [];
        }

        $content = file_get_contents($this->queueFile);
        if (empty($content)) {
            return [];
        }

        $queue = json_decode($content, true);
        if ($queue === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Invalid JSON in queue file, returning empty array', [
                'file' => $this->queueFile,
                'json_error' => json_last_error_msg()
            ]);
            return [];
        }

        return $queue ?: [];
    }

    /**
     * Генерирует уникальный ID задачи
     *
     * @return string UUID-подобный ID
     */
    private function generateTaskId()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Извлекает ID сущности из данных вебхука
     *
     * @param array $webhookData Данные вебхука
     * @return string|null ID сущности
     */
    private function extractEntityId(array $webhookData)
    {
        // Сначала пытаемся из FIELDS
        $entityId = $webhookData['data']['FIELDS']['ID'] ?? null;
        if ($entityId !== null) {
            return (string)$entityId;
        }

        // Затем из корня data
        $entityId = $webhookData['data']['ID'] ?? null;
        if ($entityId !== null) {
            return (string)$entityId;
        }

        return null;
    }

    /**
     * Определяет тип сущности по названию события
     *
     * @param string $event Название события
     * @return string Тип сущности
     */
    private function extractEntityType($event)
    {
        if (str_contains($event, 'CONTACT')) {
            return 'contact';
        } elseif (str_contains($event, 'COMPANY')) {
            return 'company';
        } elseif (str_contains($event, 'DYNAMICITEM')) {
            return 'smart_process';
        }

        return 'unknown';
    }
}