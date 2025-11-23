# Использование смарт-процессов для изменения и удаления данных

## Описание

Реализованы методы для создания карточек в смарт-процессах "Изменение данных в ЛК" и "Удаление пользовательских данных" согласно ТЗ (разделы 8 и 10).

## Конфигурация

В файле `src/config/bitrix24.php` должны быть настроены:

1. **ID смарт-процессов:**
   ```php
   'smart_process_change_data_id' => 'ID_СМАРТ_ПРОЦЕССА', // ID смарт-процесса "Изменение данных в ЛК"
   'smart_process_delete_data_id' => 'ID_СМАРТ_ПРОЦЕССА', // ID смарт-процесса "Удаление пользовательских данных"
   ```

2. **Маппинг полей** (уже настроено в конфиге):
   - `smart_process_change_data` - поля для "Изменение данных в ЛК"
   - `smart_process_delete_data` - поля для "Удаление пользовательских данных"

## Использование

### Создание карточки "Изменение данных в ЛК"

Используется при изменении пользователем личных данных или данных о компании через ЛК (раздел 8 ТЗ).

```php
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/webhooks/bitrix24.php';

$config = require_once __DIR__ . '/src/config/bitrix24.php';
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);

// Данные для создания карточки
$changeData = [
    'contact_id' => '123', // ID контакта (обязательно)
    'company_id' => '456', // ID компании (если есть)
    'manager_id' => '789', // ID менеджера (если есть)
    
    // Изменение личных данных
    'new_email' => 'newemail@example.com',
    'new_phone' => '+7 (999) 123-45-67',
    'change_reason_personal' => 'Смена места работы',
    
    // Изменение данных о компании
    'new_company_name' => 'Новое название компании',
    'new_company_website' => 'https://newcompany.ru',
    'new_company_inn' => '1234567890',
    'new_company_phone' => '+7 (999) 765-43-21',
    'change_reason_company' => 'Реорганизация компании'
];

$result = createChangeDataCard($changeData, $bitrixAPI, $logger);

if ($result) {
    echo "Карточка создана, ID: " . $result['id'];
} else {
    echo "Ошибка при создании карточки";
}
```

### Создание карточки "Удаление пользовательских данных"

Используется при удалении ЛК пользователем (раздел 10 ТЗ).

```php
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/webhooks/bitrix24.php';

$config = require_once __DIR__ . '/src/config/bitrix24.php';
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);

// Данные для создания карточки
$deleteData = [
    'contact_id' => '123', // ID контакта (обязательно)
    'company_id' => '456', // ID компании (если есть)
    'manager_id' => '789'  // ID менеджера (если есть)
];

$result = createDeleteDataCard($deleteData, $bitrixAPI, $logger);

if ($result) {
    echo "Карточка создана, ID: " . $result['id'];
} else {
    echo "Ошибка при создании карточки";
}
```

## Прямое использование методов API

Также можно использовать методы напрямую из класса `Bitrix24API`:

```php
// Создание карточки "Изменение данных в ЛК"
$result = $bitrixAPI->createChangeDataCard($changeData);

// Создание карточки "Удаление пользовательских данных"
$result = $bitrixAPI->createDeleteDataCard($deleteData);

// Универсальный метод для создания элемента смарт-процесса
$result = $bitrixAPI->addSmartProcessItem($entityTypeId, $fields);
```

## Интеграция в API endpoints ЛК

Эти функции должны вызываться из API endpoints личного кабинета:

1. **При изменении личных данных** (п.8.3 ТЗ):
   - После подтверждения изменений пользователем
   - Перед показом сообщения "Ваш запрос на изменение личных данных принят"

2. **При изменении данных о компании** (п.8.7 ТЗ):
   - После подтверждения изменений пользователем
   - Перед показом сообщения "Ваш запрос на изменение данных о компании принят"

3. **При удалении ЛК** (п.10.4 ТЗ):
   - После подтверждения удаления пользователем
   - Перед удалением ЛК из системы

## Пример интеграции в API endpoint

```php
// Пример endpoint для изменения личных данных
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_personal_data') {
    // ... валидация данных ...
    
    // Создаем карточку в смарт-процессе
    $changeData = [
        'contact_id' => $contactId,
        'company_id' => $companyId,
        'manager_id' => $managerId,
        'new_email' => $_POST['new_email'] ?? '',
        'new_phone' => $_POST['new_phone'] ?? '',
        'change_reason_personal' => $_POST['reason'] ?? ''
    ];
    
    $cardResult = createChangeDataCard($changeData, $bitrixAPI, $logger);
    
    if ($cardResult) {
        // Показываем сообщение пользователю
        echo json_encode(['status' => 'success', 'message' => 'Ваш запрос на изменение личных данных принят']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Ошибка при обработке запроса']);
    }
}
```

## Требования

1. В Bitrix24 должны быть созданы смарт-процессы:
   - "Изменение данных в ЛК"
   - "Удаление пользовательских данных"

2. В конфиге должны быть указаны ID этих смарт-процессов

3. В конфиге должны быть указаны коды всех полей в маппинге

4. Поля в смарт-процессах должны соответствовать маппингу в конфиге

## Логирование

Все операции логируются через `Logger`:
- Успешное создание карточек
- Ошибки при создании
- Отсутствие конфигурации
- Детали создаваемых полей

Логи доступны в файле, указанном в конфиге (`src/logs/bitrix24_webhooks.log`).

