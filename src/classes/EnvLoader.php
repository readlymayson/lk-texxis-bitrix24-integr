<?php
# -*- coding: utf-8 -*-

/**
 * Класс для загрузки переменных окружения из .env файла
 */
class EnvLoader
{
    private static $loaded = false;

    /**
     * Загрузка переменных окружения из .env файла
     */
    public static function load($envFile = null)
    {
        if (self::$loaded) {
            return;
        }

        $envFile = $envFile ?: self::findEnvFile();

        if (!$envFile || !file_exists($envFile)) {
            // Если .env нет, используем переменные окружения системы
            self::$loaded = true;
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Пропускаем комментарии
            if (str_starts_with($line, '#')) {
                continue;
            }

            // Разбираем KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Убираем кавычки если есть
                $value = self::stripQuotes($value);

                // Устанавливаем переменную окружения
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    /**
     * Получение значения переменной окружения
     */
    public static function get($key, $default = null)
    {
        // Проверяем в разных местах
        return $_ENV[$key] ??
               $_SERVER[$key] ??
               getenv($key) ??
               $default;
    }

    /**
     * Получение значения с приведением типов
     */
    public static function getBool($key, $default = false)
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function getInt($key, $default = 0)
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * Поиск файла .env
     */
    private static function findEnvFile()
    {
        $possibleFiles = [
            __DIR__ . '/../../.env',
            __DIR__ . '/../../.env.local',
            '.env',
            '.env.local'
        ];

        foreach ($possibleFiles as $file) {
            if (file_exists($file)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Удаление кавычек вокруг значения
     */
    private static function stripQuotes($value)
    {
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
