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
        'smart_process_id' => 1142, // ID смарт-процесса для проектов
        'smart_process_change_data_id' => 1152, // ID смарт-процесса "Изменение данных в ЛК"
        'smart_process_delete_data_id' => 1164, // ID смарт-процесса "Удаление пользовательских данных"
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
            'lk_client_field' => 'UF_CRM_1763468430', // Поле "ЛК клиента" в контакте
            'lk_client_values' => [3118,3120,3122], // Допустимые значения поля "ЛК клиента"
            'lk_delete_values' => [3116,3294], // Значения поля ЛК, при которых удаляются данные из БД
            'email' => 'EMAIL',
            'phone' => 'PHONE',
            'name' => 'NAME',
            'last_name' => 'LAST_NAME',
            'second_name' => 'SECOND_NAME',
            'type_id' => 'TYPE_ID',
            'company_id' => 'COMPANY_ID',
            'manager_id' => 'ASSIGNED_BY_ID',
            'organization' => 'COMPANY_ID', // Организация (п.3.2) - связь с компанией, название берется из связанной компании
            'agent_contract_status' => 'UF_CRM_65CA1E9F08E72', // Статус "Агентский договор" (п.3.6) - список с заданными значениями
        ],
        'company' => [
            'title' => 'TITLE',
            'email' => 'EMAIL', 
            'phone' => 'PHONE',
            'contact_id' => 'CONTACT_ID',
            'inn' => '', // ИНН компании
            'website' => 'WEB', // Сайт компании
            'partner_contract_status' => 'UF_CRM_65CA23468EF2E', // Статус "Партнерский договор" (п.3.7) - список с заданными значениями
        ],
        // Маппинг полей проектов (смарт-процессов)
        'smart_process' => [
            'client_id' => 'contactId',                              // Связь с клиентом (контактом)
            'company_id' => 'companyId',                             // Связь с компанией
            'organization_name' => 'ufCrm42_1758957874',         // Название организации
            'object_name' => 'ufCrm42_1758958190',                // Название/тип объекта
            'request_type' => 'ufCrm42_1760082644',               // Тип запроса
            'system_types' => 'ufCrm42_1760082842',               // Тип системы (список)
            'equipment_list_text' => "ufCrm42_1762762912",      // Текстовое поле для состава оборудования
            'equipment_list' => 'ufCrm42_1770285065737',             // Состав оборудования (ссылка на файл)
            'location' => 'ufCrm42_1758958310',                   // Адрес объекта
            'technical_description' => 'ufCrm42_1758959081',      // Техническое описание объекта (многострочный текст)
            'competitors' => 'ufCrm42_1758959060',                // Возможные конкуренты
            'implementation_date' => 'ufCrm42_1770285213228',        // Планируемая дата реализации
            'marketing_discount' => 'ufCrm42_1770285289427',         // Маркетинговая скидка (чекбокс)
            'manager_id' => 'assignedById',                         // Ответственный менеджер
            'status' => 'stageId',                                    // Статус смарт-процесса
        ],
        // Маппинг полей смарт-процесса "Изменение данных в ЛК"
        'smart_process_change_data' => [
            'contact_id' => 'contactId',                       // Связь с контактом
            'company_id' => 'companyId',                       // Связь с компанией (если есть)
            'manager_id' => 'assignedById',                    // Ответственный менеджер
            'new_email' => 'ufCrm46_1762422871',           // Новый e-mail
            'new_phone' => 'ufCrm46_1762422936',           // Новый телефон
            'change_reason_personal' => 'ufCrm46_1762423018', // Причина изменения личных данных
            'new_company_name' => 'ufCrm46_1762423550',    // Название новой компании
            'new_company_website' => 'ufCrm46_1762423598', // Сайт новой компании
            'new_company_inn' => 'ufCrm46_1762423988',     // ИНН новой компании
            'new_company_phone' => 'ufCrm46_1762423999',   // Телефон новой компании
            'change_reason_company' => 'ufCrm46_1762424047', // Причина изменения данных о компании
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
                'telegram' => 'UF_USR_1770298945517',   // username или ссылка
                'whatsapp' => 'UF_USR_1770298951654',   // номер телефона или ссылка
            ],
        ],
    ],

    // Настройки обработки событий
    'events' => [
        'enabled_events' => [
            'ONCRMCONTACTUPDATE',    // Изменение контакта
            'ONCRMCONTACTDELETE',    // Удаление контакта
            'ONCRMCOMPANYUPDATE',    // Изменение компании
            'ONCRMCOMPANYDELETE',    // Удаление компании
            'ONCRM_DYNAMIC_ITEM_UPDATE', // Изменение смарт-процесса
            'ONCRM_DYNAMIC_ITEM_DELETE', // Удаление смарт-процесса
            'ONCRMDYNAMICITEMUPDATE', // Изменение смарт-процесса (альтернатива ONCRM_DYNAMIC_ITEM_UPDATE)
            'ONCRMDYNAMICITEMDELETE', // Удаление смарт-процесса (альтернатива ONCRM_DYNAMIC_ITEM_DELETE)
        ],

        // Задержки между повторными попытками (секунды)
        'retry_delays' => [5, 30, 300, 3600],

        // Максимальное количество повторных попыток
        'max_retries' => 3,
    ],
];

