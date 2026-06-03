# Настройка запуска БП 614 при первой авторизации пользователя

## Описание

При первом входе пользователя на сайт (событие `OnAfterUserAuthorize`) запускается бизнес-процесс 614 в контакте облачного Битрикс24.

**Связь пользователя сайта с контактом в CRM:**
- При создании ЛК в `LocalStorage_prod::createLK()` пользователю на сайте устанавливается `XML_ID = ID контакта в облачном Bitrix24`
- Для менеджеров `XML_ID = "USER_" + ID` — они исключаются проверкой `strpos($arUser["XML_ID"], "USER_")===false`

## Код для вставки в `local/php_interface/init.php`

```php
<?php

use Bitrix\Main\EventManager;

// Подключение классов проекта для запуска БП в облачном Bitrix24
$projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/path/to/application/lk'; // УКАЖИТЕ РЕАЛЬНЫЙ ПУТЬ
require_once $projectRoot . '/src/classes/EnvLoader.php';
require_once $projectRoot . '/src/classes/Logger.php';
require_once $projectRoot . '/src/classes/Bitrix24API.php';

EventManager::getInstance()->addEventHandler(
    'main',
    'OnAfterUserAuthorize',
    'checkFirstAuth'
);

function checkFirstAuth(&$arFields)
{
    // Проверяем, что событие сработало при реальной авторизации
    if ($arFields['USER_ID'] > 0 && isset($arFields['PASSWORD_CHANGED']) && $arFields['PASSWORD_CHANGED'] == 'N') {

        $rsUser = CUser::GetByID($arFields['USER_ID']);
        $arUser = $rsUser->Fetch();

        // LOGIN_ATTEMPTS — сколько раз пользователь заходил на сайт.
        // Проверяем, что вход выполнен впервые (значение <= 1).
        // XML_ID заполнен и не содержит "USER_" (значит это не менеджер, а клиент).
        if ($arUser['LOGIN_ATTEMPTS'] <= 1 && !empty($arUser["XML_ID"]) && strpos($arUser["XML_ID"], "USER_") === false) {

            $contactId = $arUser["XML_ID"];

            try {
                $projectRoot = $_SERVER['DOCUMENT_ROOT'] . '/path/to/application/lk'; // УКАЖИТЕ РЕАЛЬНЫЙ ПУТЬ
                $config = require $projectRoot . '/src/config/bitrix24.php';
                $logger = new Logger($config);
                $bitrixAPI = new Bitrix24API($config, $logger);

                $result = $bitrixAPI->startFirstAuthBusinessProcess($contactId);

                if ($result && isset($result['result'])) {
                    AddEventToStatFile('first_auth', 'success', 'bp_started', $contactId);
                } else {
                    AddEventToStatFile('first_auth', 'error', 'bp_failed', $contactId);
                }
            } catch (Exception $e) {
                AddEventToStatFile('first_auth', 'error', 'exception', $e->getMessage());
            }
        }
    }
}
```

## Важно!

1. **Замените `/path/to/application/lk`** на реальный путь к корню проекта на сервере.
2. Если проект находится в `$_SERVER['DOCUMENT_ROOT']`, используйте относительный путь.
3. Убедитесь, что `bitrix24.php` в конфиге использует правильный профиль (dev/prod/prod_mini) — при необходимости замените путь к конфигу.
4. БП 614 должен быть шаблоном бизнес-процесса для сущности **Контакты** в облачном Bitrix24, и должен быть запущен (активен).
5. Параметры `PARAMETERS` БП 614 не требуют дополнительных значений — если нужны, их нужно добавить в метод `startFirstAuthBusinessProcess()`.
