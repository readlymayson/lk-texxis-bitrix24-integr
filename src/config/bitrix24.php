<?php
# -*- coding: utf-8 -*-

/**
 * Конфигурация для интеграции с Битрикс24
 */

// Загрузка переменных окружения
require_once __DIR__ . '/../classes/EnvLoader.php';
EnvLoader::load();

return [
    // Настройки подключения к Битрикс24
    'bitrix24' => [
        'webhook_url' => EnvLoader::get('BITRIX24_WEBHOOK_URL', ''),
        'webhook_secret' => EnvLoader::get('BITRIX24_WEBHOOK_SECRET', ''),
        'application_token' => EnvLoader::get('BITRIX24_APPLICATION_TOKEN', ''), // Токен приложения для валидации webhook
        'client_id' => EnvLoader::get('BITRIX24_CLIENT_ID', ''),
        'client_secret' => EnvLoader::get('BITRIX24_CLIENT_SECRET', ''),
        'timeout' => EnvLoader::getInt('BITRIX24_TIMEOUT', 30), // Timeout для API запросов (секунды)
    ],

    // Настройки локального хранилища данных
    // Все данные хранятся локально в JSON файлах в директории data/
    'local_storage' => [
        'data_dir' => __DIR__ . '/../data',
        'contacts_file' => __DIR__ . '/../data/contacts.json',
        'companies_file' => __DIR__ . '/../data/companies.json',
        'deals_file' => __DIR__ . '/../data/deals.json',
    ],

    // Настройки логирования
    'logging' => [
        'enabled' => EnvLoader::getBool('LOG_ENABLED', true),
        'level' => EnvLoader::get('LOG_LEVEL', 'INFO'),
        'file' => __DIR__ . '/../logs/bitrix24_webhooks.log',
        'max_size' => 10 * 1024 * 1024, // 10MB
    ],

    // Маппинг полей Битрикс24 -> ЛК
    // ВАЖНО: Email теперь опциональный - личный кабинет можно создать без email
    'field_mapping' => [
        'contact' => [
            'lk_client_field' => 'UF_CRM_1763531846040', // Поле "ЛК клиента" в контакте
            'lk_client_values' => ["46"], // Допустимые значения поля "ЛК клиента"
            'email' => 'EMAIL', // Опционально - если нет email, ЛК создается без него
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL', // Опционально - если нет email, компания обрабатывается без него
            'phone' => 'PHONE',
        ],
        'deal' => [
            'title' => 'TITLE',
            'stage_id' => 'STAGE_ID',
        ],
    ],

    // Настройки обработки событий
    'events' => [
        'enabled_events' => [
            'ONCRMCONTACTUPDATE',    // Изменение контакта
            'ONCRMCONTACTADD',       // Создание контакта
            'ONCRMCONTACTDELETE',    // Удаление контакта
            'ONCRMCOMPANYUPDATE',    // Изменение компании
            'ONCRMCOMPANYADD',       // Создание компании
            'ONCRMCOMPANYDELETE',    // Удаление компании
            'ONCRMDEALUPDATE',       // Изменение сделки
            'ONCRMDEALADD',          // Создание сделки
            'ONCRM_DYNAMIC_ITEM_UPDATE', // Изменение смарт-процесса
        ],

        // Задержки между повторными попытками (секунды)
        'retry_delays' => [5, 30, 300, 3600],

        // Максимальное количество повторных попыток
        'max_retries' => 3,
    ],
];

