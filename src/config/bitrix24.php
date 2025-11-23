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
        // ID смарт-процессов по ТЗ (разделы 8, 10)
        'smart_process_change_data_id' => 1040, // ID смарт-процесса "Изменение данных в ЛК" (п.8.4) - ТРЕБУЕТСЯ УТОЧНИТЬ
        'smart_process_delete_data_id' => 1044, // ID смарт-процесса "Удаление пользовательских данных" (п.10.4) - ТРЕБУЕТСЯ УТОЧНИТЬ
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
            'lk_delete_value' => 44, // Значение поля ЛК, при котором удаляются данные из БД (задать нужное значение)
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
            'second_name' => 'SECOND_NAME',
            'type_id' => 'TYPE_ID',
            'company_id' => 'COMPANY_ID',
            'manager_id' => 'ASSIGNED_BY_ID',
            // Поля по ТЗ (раздел 3)
            'organization' => 'COMPANY_ID', // Организация (п.3.2) - связь с компанией, название берется из связанной компании
            'agent_contract_status' => '', // Статус "Агентский договор" (п.3.6) - ТРЕБУЕТСЯ УТОЧНИТЬ КОД ПОЛЯ В Б24
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL', 
            'phone' => 'PHONE',
            'contact_id' => 'CONTACT_ID',
            // Поля по ТЗ (раздел 3.7, 8.6)
            'inn' => 'INN', // ИНН компании (п.8.6)
            'website' => 'WEB', // Сайт компании (п.8.6) - стандартное поле WEB в Bitrix24
            'partner_contract_status' => '', // Статус "Партнерский договор" (п.3.7) - ТРЕБУЕТСЯ УТОЧНИТЬ КОД ПОЛЯ В Б24
        ],
        'deal' => [
            'title' => 'TITLE',
            'stage_id' => 'STAGE_ID',
            'contact_id' => 'CONTACT_ID',
            'company_id' => 'COMPANY_ID',
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
        // Маппинг полей смарт-процесса "Изменение данных в ЛК" (раздел 8.9 ТЗ)
        'smart_process_change_data' => [
            'contact_id' => 'contactId',                       // Связь с контактом
            'company_id' => 'companyId',                       // Связь с компанией (если есть)
            'manager_id' => 'assignedById',                    // Ответственный менеджер
            'new_email' => 'ufCrm8_1762422871',            // Новый e-mail (п.8.9)
            'new_phone' => 'ufCrm8_1762422936',            // Новый телефон (п.8.9)
            'change_reason_personal' => 'ufCrm8_1762423018', // Причина изменения личных данных (п.8.9)
            'new_company_name' => 'ufCrm8_1762423550',     // Название новой компании (п.8.9)
            'new_company_website' => 'ufCrm8_1762423598',  // Сайт новой компании (п.8.9)
            'new_company_inn' => 'ufCrm8_1762423988',      // ИНН новой компании (п.8.9)
            'new_company_phone' => 'ufCrm8_1762423999',    // Телефон новой компании (п.8.9)
            'change_reason_company' => 'ufCrm8_1762424047', // Причина изменения данных о компании (п.8.9)
        ],
        // Маппинг полей смарт-процесса "Удаление пользовательских данных" (раздел 10.5 ТЗ)
        'smart_process_delete_data' => [
            'contact_id' => 'contactId',                       // Связь с контактом
            'company_id' => 'companyId',                       // Связь с компанией (если есть)
            'manager_id' => 'assignedById',                    // Ответственный менеджер
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

