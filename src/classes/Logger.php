<?php

/**
 * Класс для логирования действий интеграции с Битрикс24
 */
class Logger
{
    const DEBUG = 'DEBUG';
    const INFO = 'INFO';
    const WARNING = 'WARNING';
    const ERROR = 'ERROR';

    private $config;
    private $logFile;

    public function __construct($config)
    {
        $this->config = $config['logging'];
        $this->logFile = $this->config['file'];
        $this->ensureLogDirectory();
    }

    /**
     * Логирование отладочной информации
     */
    public function debug($message, $context = [])
    {
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Логирование информационных сообщений
     */
    public function info($message, $context = [])
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Логирование предупреждений
     */
    public function warning($message, $context = [])
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Логирование ошибок
     */
    public function error($message, $context = [])
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Основной метод логирования
     */
    private function log($level, $message, $context = [])
    {
        if (!$this->config['enabled'] || $this->shouldSkipLevel($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = empty($context) ? '' : ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $logEntry = sprintf("[%s] %s: %s%s\n", $timestamp, $level, $message, $contextStr);

        $this->writeToFile($logEntry);
        $this->rotateLogIfNeeded();
    }

    /**
     * Запись в файл с учетом кодировки UTF-8
     */
    private function writeToFile($content)
    {
        $fp = fopen($this->logFile, 'a', false, stream_context_create([
            'file' => [
                'encoding' => 'utf-8'
            ]
        ]));

        if ($fp) {
            fwrite($fp, $content);
            fclose($fp);
        }
    }

    /**
     * Проверка необходимости ротации лога
     */
    private function rotateLogIfNeeded()
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        $fileSize = filesize($this->logFile);
        if ($fileSize > $this->config['max_size']) {
            $backupFile = $this->logFile . '.' . date('Y-m-d_H-i-s') . '.bak';
            rename($this->logFile, $backupFile);
        }
    }

    /**
     * Проверка уровня логирования
     */
    private function shouldSkipLevel($level)
    {
        $levels = [self::DEBUG => 1, self::INFO => 2, self::WARNING => 3, self::ERROR => 4];
        $currentLevel = $levels[$this->config['level']] ?? 2;

        return $levels[$level] < $currentLevel;
    }

    /**
     * Создание директории для логов
     */
    private function ensureLogDirectory()
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
}

