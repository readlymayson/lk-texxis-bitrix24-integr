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
        'smart_process_id' => 1036, // ID смарт-процесса для проектов
    ],

    // Настройки локального хранилища данных
    // Все данные хранятся локально в JSON файлах в директории data/
    'local_storage' => [
        'data_dir' => __DIR__ . '/../data',
        'contacts_file' => __DIR__ . '/../data/contacts.json',
        'companies_file' => __DIR__ . '/../data/companies.json',
        'deals_file' => __DIR__ . '/../data/deals.json',
        'projects_file' => __DIR__ . '/../data/projects.json', // ДОБАВИТЬ ДЛЯ ПРОЕКТОВ
        'managers_file' => __DIR__ . '/../data/managers.json', // ДОБАВИТЬ ДЛЯ МЕНЕДЖЕРОВ
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
            'lk_client_field' => 'UF_CRM_1763531846040', // (Заменить для боевого) Поле "ЛК клиента" в контакте
            'lk_client_values' => ['46','3118','3120','3122'], // Допустимые значения поля "ЛК клиента"
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
        // Маппинг полей проектов (смарт-процессов)
        'smart_process' => [
            'organization_name' => 'ufCrm6_1758957874',        // Название организации конечного заказчика
            'object_name' => 'ufCrm6_1758958190',              // Название/тип объекта
            'system_type' => 'ufCrm6_1758959081',              // Состав оборудования из спецификации проекта
            'location' => 'ufCrm6_1758958310',                 // Адрес объекта
            'implementation_date' => 'ufCrm6_1758959105',      // Дата реализации проекта
            'status' => 'stageId',                             // Статус смарт-процесса
            'client_id' => 'contactId',                         // Связь с клиентом (контактом)
        ],
        // ДОБАВИТЬ МАППИНГ МЕНЕДЖЕРОВ
        'user' => [
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
            'email' => 'EMAIL',
            'phone' => 'PERSONAL_PHONE',
            'position' => 'WORK_POSITION',
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
            'ONCRM_DYNAMIC_ITEM_ADD',    // Создание смарт-процесса
        ],

        // Задержки между повторными попытками (секунды)
        'retry_delays' => [5, 30, 300, 3600],

        // Максимальное количество повторных попыток
        'max_retries' => 3,
    ],
];

