<?php

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
        'application_token' => EnvLoader::get('BITRIX24_APPLICATION_TOKEN', ''), // Токен приложения для валидации webhook
        'timeout' => EnvLoader::getInt('BITRIX24_TIMEOUT', 30), // Timeout для API запросов (секунды)
        'email_from' => '', // Email адрес отправителя для писем (опционально)
        'smart_process_id' => 1042, // ID смарт-процесса для проектов
        // ID смарт-процессов по ТЗ (разделы 8, 10)
        'smart_process_change_data_id' => 1046, // ID смарт-процесса "Изменение данных в ЛК"
        'smart_process_delete_data_id' => 1050, // ID смарт-процесса "Удаление пользовательских данных"
        'email_business_process_id' => "", // ID бизнес-процесса для отправки email контакту о создании ЛК
    ],

    // Настройки локального хранилища данных
    // Все данные хранятся локально в JSON файлах в директории data/
    'local_storage' => [
        'data_dir' => __DIR__ . '/../data',
        'contacts_file' => __DIR__ . '/../data/contacts.json',
        'companies_file' => __DIR__ . '/../data/companies.json',
        'projects_file' => __DIR__ . '/../data/projects.json',
        'managers_file' => __DIR__ . '/../data/managers.json',
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
            'lk_client_field' => 'UF_CRM_1768129842370', // Поле "ЛК клиента" в контакте
            'lk_client_values' => ['47','49','51'], // Допустимые значения поля "ЛК клиента"
            'lk_delete_value' => 45, // Значение поля ЛК, при котором удаляются данные из БД
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
            'second_name' => 'SECOND_NAME',
            'type_id' => 'TYPE_ID',
            'company_id' => 'COMPANY_ID',
            'manager_id' => 'ASSIGNED_BY_ID',
            'organization' => 'COMPANY_ID', // Организация (п.3.2) - связь с компанией, название берется из связанной компании
            'agent_contract_status' => 'UF_CRM_1768129874400', // Статус "Агентский договор" (п.3.6) - список с заданными значениями
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL', 
            'phone' => 'PHONE',
            'contact_id' => 'CONTACT_ID',
            'inn' => 'UF_CRM_1768129947247', // ИНН компании
            'website' => 'WEB', // Сайт компании
            'partner_contract_status' => 'UF_CRM_1768129940732', // Статус "Партнерский договор" (п.3.7) - список с заданными значениями
        ],
        // Маппинг полей проектов (смарт-процессов)
        'smart_process' => [
            'client_id' => 'contactId',                              // Связь с клиентом (контактом)
            'organization_name' => 'ufCrm7_1768130049371',         // Название организации
            'object_name' => 'ufCrm7_1768130056401',                // Название/тип объекта
            'request_type' => 'ufCrm7_1768130081539',               // Тип запроса
            'system_types' => 'ufCrm7_1768130111325',               // Тип системы (список)
            'equipment_list' => 'ufCrm7_1768130130483',             // Состав оборудования (ссылка на файл)
            'location' => 'ufCrm7_1768130146776',                   // Адрес объекта
            'technical_description' => 'ufCrm7_1768130163081',      // Техническое описание объекта (многострочный текст)
            'competitors' => 'ufCrm7_1768130168777',                // Возможные конкуренты
            'implementation_date' => 'ufCrm7_1768130177607',        // Планируемая дата реализации
            'marketing_discount' => 'ufCrm7_1768130185822',         // Маркетинговая скидка (чекбокс)
            'status' => 'stageId',                                    // Статус смарт-процесса
        ],
        // Маппинг полей смарт-процесса "Изменение данных в ЛК"
        'smart_process_change_data' => [
            'contact_id' => 'contactId',                       // Связь с контактом
            'company_id' => 'companyId',                       // Связь с компанией (если есть)
            'manager_id' => 'assignedById',                    // Ответственный менеджер
            'new_email' => 'ufCrm9_1768130256626',           // Новый e-mail
            'new_phone' => 'ufCrm9_1768130262174',           // Новый телефон
            'change_reason_personal' => 'ufCrm9_1768130269031', // Причина изменения личных данных
            'new_company_name' => 'ufCrm9_1768130275443',    // Название новой компании
            'new_company_website' => 'ufCrm9_1768130285153', // Сайт новой компании
            'new_company_inn' => 'ufCrm9_1768130291668',     // ИНН новой компании
            'new_company_phone' => 'ufCrm9_1768130300168',   // Телефон новой компании
            'change_reason_company' => 'ufCrm9_1768130307424', // Причина изменения данных о компании
        ],
        // Маппинг полей смарт-процесса "Удаление пользовательских данных"
        'smart_process_delete_data' => [
            'contact_id' => 'contactId',                       // Связь с контактом
            'company_id' => 'companyId',                       // Связь с компанией (если есть)
            'manager_id' => 'assignedById',                    // Ответственный менеджер
        ],
        'user' => [
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
            'email' => 'EMAIL',
            'phone' => 'PERSONAL_MOBILE', // Используем мобильный номер менеджера
            'position' => 'WORK_POSITION',
            'photo' => 'PERSONAL_PHOTO', // Фото менеджера
            'messengers' => [
                'telegram' => 'UF_USR_1768131680577',   // username или ссылка
                'whatsapp' => 'UF_USR_1768131686851',   // номер телефона или ссылка
            ],
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
            'ONCRM_DYNAMIC_ITEM_UPDATE', // Изменение смарт-процесса
            'ONCRM_DYNAMIC_ITEM_ADD',    // Создание смарт-процесса
            'ONCRM_DYNAMIC_ITEM_DELETE', // Удаление смарт-процесса
            'ONCRMDYNAMICITEMUPDATE', // Изменение смарт-процесса (альтернатива ONCRM_DYNAMIC_ITEM_UPDATE)
            'ONCRMDYNAMICITEMADD', // Создание смарт-процесса (альтернатива ONCRM_DYNAMIC_ITEM_ADD)
            'ONCRMDYNAMICITEMDELETE', // Удаление смарт-процесса (альтернатива ONCRM_DYNAMIC_ITEM_DELETE)
        ],

        // Задержки между повторными попытками (секунды)
        'retry_delays' => [5, 30, 300, 3600],

        // Максимальное количество повторных попыток
        'max_retries' => 3,
    ],
];

