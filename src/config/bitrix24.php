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
        'client_id' => EnvLoader::get('BITRIX24_CLIENT_ID', ''),
        'client_secret' => EnvLoader::get('BITRIX24_CLIENT_SECRET', ''),
    ],

    // Настройки логирования
    'logging' => [
        'enabled' => EnvLoader::getBool('LOG_ENABLED', true),
        'level' => EnvLoader::get('LOG_LEVEL', 'INFO'),
        'file' => __DIR__ . '/../logs/bitrix24_webhooks.log',
        'max_size' => 10 * 1024 * 1024, // 10MB
    ],

    // Маппинг полей Битрикс24 -> ЛК
    'field_mapping' => [
        'contact' => [
            'lk_client_field' => 'UF_CRM_1763468430', // Поле "ЛК клиента" в контакте
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL',
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

