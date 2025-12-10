# Обработка типов данных полей проекта

**Дата:** 04.12.2025  
**Версия:** 1.1 (с полной обработкой типов)

## Обзор

Для корректной работы с новыми полями проекта реализована специальная обработка различных типов данных, которые приходят от Bitrix24 API.

## Типы полей и их обработка

### 1. Списочное поле (Select) - "Тип запросов"

**Поле:** `request_type`  
**ID Bitrix24:** `ufCrm6_1764840201124`

#### Форматы данных от Bitrix24:

```php
// Вариант 1: Простая строка (ID значения)
"123"

// Вариант 2: Массив с одним элементом
["123"]

// Вариант 3: Объект с ID
{"ID": "123", "VALUE": "Консультация"}
```

#### Обработка:

```php
$requestTypeRaw = $projectData[$mapping['request_type']] ?? null;
$requestType = '';

if (!empty($requestTypeRaw)) {
    if (is_array($requestTypeRaw)) {
        // Если массив, берем первый элемент или ID из объекта
        $requestType = $requestTypeRaw[0] ?? $requestTypeRaw['ID'] ?? '';
    } else {
        // Если строка, используем как есть
        $requestType = (string)$requestTypeRaw;
    }
}
```

#### Результат:
- Сохраняется: `"123"` (строка с ID значения)
- Отображается: `"123"` (требуется декодер для получения текстовой метки)

---

### 2. Файловое поле (File) - "Перечень оборудования"

**Поле:** `equipment_list`  
**ID Bitrix24:** `ufCrm6_1764840244682`

#### Форматы данных от Bitrix24:

```php
// Вариант 1: Массив объектов файлов
[
    {
        "id": 123,
        "name": "equipment_list.pdf",
        "downloadUrl": "https://bitrix24.com/download/123",
        "size": 2048576
    },
    {
        "id": 124,
        "name": "specifications.xlsx",
        "downloadUrl": "https://bitrix24.com/download/124",
        "size": 1024000
    }
]

// Вариант 2: Массив ID файлов
[123, 124]

// Вариант 3: Одиночный ID
123
```

#### Обработка:

```php
$equipmentListRaw = $projectData[$mapping['equipment_list']] ?? null;
$equipmentList = null;

if (!empty($equipmentListRaw)) {
    if (is_array($equipmentListRaw)) {
        $equipmentList = [];
        foreach ($equipmentListRaw as $file) {
            if (is_array($file)) {
                // Полный объект файла
                $equipmentList[] = [
                    'id' => $file['id'] ?? $file['ID'] ?? null,
                    'name' => $file['name'] ?? $file['NAME'] ?? null,
                    'url' => $file['downloadUrl'] ?? $file['DOWNLOAD_URL'] ?? null,
                    'size' => $file['size'] ?? $file['SIZE'] ?? null
                ];
            } else {
                // Только ID файла
                $equipmentList[] = ['id' => $file];
            }
        }
    } else {
        // Одиночный ID
        $equipmentList = [['id' => $equipmentListRaw]];
    }
}
```

#### Результат:

```php
// Сохраняется в БД:
[
    {
        "id": 123,
        "name": "equipment_list.pdf",
        "url": "https://bitrix24.com/download/123",
        "size": 2048576
    }
]
```

#### Отображение в HTML:

```php
<?php if (!empty($project['equipment_list']) && is_array($project['equipment_list'])): ?>
    <small>
    <?php foreach ($project['equipment_list'] as $file): ?>
        <?php $fileName = $file['name'] ?? 'Файл #' . ($file['id'] ?? '?'); ?>
        <?php if (!empty($file['url'])): ?>
            <a href="<?= htmlspecialchars($file['url']) ?>" target="_blank">
                <i class="fas fa-file-download"></i> <?= htmlspecialchars($fileName) ?>
            </a>
        <?php else: ?>
            <i class="fas fa-file"></i> <?= htmlspecialchars($fileName) ?>
        <?php endif; ?>
        <br>
    <?php endforeach; ?>
    </small>
<?php else: ?>
    -
<?php endif; ?>
```

**Результат:** Список файлов с кликабельными ссылками для скачивания

---

### 3. Текстовое поле (Text) - "Возможные конкуренты"

**Поле:** `competitors`  
**ID Bitrix24:** `ufCrm6_1764840292955`

#### Формат данных от Bitrix24:

```php
// Простая строка
"Компания А, Компания Б, Компания В"
```

#### Обработка:

```php
$competitors = $projectData[$mapping['competitors']] ?? '';
```

#### Результат:
- Сохраняется: `"Компания А, Компания Б, Компания В"`
- Отображается: `"Компания А, Компания Б, Компания В"` или `-` если пусто

---

### 4. Чекбокс (Checkbox) - "Маркетинговая скидка"

**Поле:** `marketing_discount`  
**ID Bitrix24:** `ufCrm6_1764840330293`

#### Форматы данных от Bitrix24:

```php
// Вариант 1: Boolean
true / false

// Вариант 2: Строка
"Y" / "N"
"YES" / "NO"
"1" / "0"
"TRUE" / "FALSE"

// Вариант 3: Число
1 / 0
```

#### Обработка:

```php
$marketingDiscountRaw = $projectData[$mapping['marketing_discount']] ?? null;
$marketingDiscount = false;

if (!empty($marketingDiscountRaw)) {
    if (is_bool($marketingDiscountRaw)) {
        // Уже boolean
        $marketingDiscount = $marketingDiscountRaw;
    } elseif (is_numeric($marketingDiscountRaw)) {
        // Число: 1 = true, 0 = false
        $marketingDiscount = (int)$marketingDiscountRaw === 1;
    } elseif (is_string($marketingDiscountRaw)) {
        // Строка: проверяем на "положительные" значения
        $marketingDiscount = in_array(
            strtoupper($marketingDiscountRaw), 
            ['Y', 'YES', 'TRUE', '1']
        );
    }
}
```

#### Результат:
- Сохраняется: `true` или `false` (boolean)
- Отображается: 
  - `true` → `<span class="badge bg-success">Да</span>`
  - `false` → `<span class="badge bg-secondary">Нет</span>`

---

## Примеры данных

### Пример 1: Полный проект со всеми полями

**Входные данные от Bitrix24:**

```json
{
  "id": 240,
  "ufCrm6_1758957874": "ООО Рога и Копыта",
  "ufCrm6_1758958190": "Офисное здание",
  "ufCrm6_1758959081": "Система видеонаблюдения",
  "ufCrm6_1758958310": "Москва, ул. Ленина, 1",
  "ufCrm6_1758959105": "2025-12-31",
  "ufCrm6_1764840201124": "123",
  "ufCrm6_1764840244682": [
    {
      "id": 456,
      "name": "equipment.pdf",
      "downloadUrl": "https://bitrix24.com/download/456",
      "size": 1024000
    }
  ],
  "ufCrm6_1764840292955": "Компания А, Компания Б",
  "ufCrm6_1764840330293": "Y",
  "stageId": "DT1036_6:NEW",
  "contactId": 2
}
```

**Сохраненные данные в LocalStorage:**

```json
{
  "bitrix_id": 240,
  "organization_name": "ООО Рога и Копыта",
  "object_name": "Офисное здание",
  "system_type": "Система видеонаблюдения",
  "location": "Москва, ул. Ленина, 1",
  "implementation_date": "2025-12-31",
  "request_type": "123",
  "equipment_list": [
    {
      "id": 456,
      "name": "equipment.pdf",
      "url": "https://bitrix24.com/download/456",
      "size": 1024000
    }
  ],
  "competitors": "Компания А, Компания Б",
  "marketing_discount": true,
  "status": "DT1036_6:NEW",
  "client_id": "2",
  "manager_id": null,
  "created_at": "2025-12-04 10:30:00",
  "updated_at": "2025-12-04 10:30:00",
  "source": "bitrix24_webhook"
}
```

### Пример 2: Проект с пустыми новыми полями

**Входные данные:**

```json
{
  "id": 241,
  "ufCrm6_1758957874": "ООО Тест",
  "ufCrm6_1758958190": "Склад",
  "ufCrm6_1758959081": "СКУД",
  "ufCrm6_1758958310": "СПб",
  "ufCrm6_1758959105": null,
  "ufCrm6_1764840201124": "",
  "ufCrm6_1764840244682": null,
  "ufCrm6_1764840292955": "",
  "ufCrm6_1764840330293": "N",
  "stageId": "DT1036_6:NEW",
  "contactId": 3
}
```

**Сохраненные данные:**

```json
{
  "bitrix_id": 241,
  "organization_name": "ООО Тест",
  "object_name": "Склад",
  "system_type": "СКУД",
  "location": "СПб",
  "implementation_date": null,
  "request_type": "",
  "equipment_list": null,
  "competitors": "",
  "marketing_discount": false,
  "status": "DT1036_6:NEW",
  "client_id": "3",
  "manager_id": null,
  "created_at": "2025-12-04 10:35:00",
  "updated_at": "2025-12-04 10:35:00",
  "source": "bitrix24_webhook"
}
```

**Отображение в таблице:**
- Тип запросов: `-`
- Перечень оборудования: `-`
- Конкуренты: `-`
- Маркетинговая скидка: `Нет` (серый бейдж)

---

## Обработка ошибок

### Отсутствующие поля

Если поле отсутствует в данных от Bitrix24, используются значения по умолчанию:

```php
'request_type' => '',              // Пустая строка
'equipment_list' => null,          // null
'competitors' => '',               // Пустая строка
'marketing_discount' => false      // false
```

### Некорректные данные

Если данные приходят в неожиданном формате:

- **Списочное поле:** Возвращается пустая строка
- **Файловое поле:** Возвращается null
- **Чекбокс:** Возвращается false

### Логирование

Все операции логируются в `logs/bitrix24_webhooks.log`:

```
[2025-12-04 10:30:00] INFO: Processing project data
[2025-12-04 10:30:00] DEBUG: request_type extracted: "123"
[2025-12-04 10:30:00] DEBUG: equipment_list files count: 1
[2025-12-04 10:30:00] DEBUG: marketing_discount converted to boolean: true
```

---

## Совместимость

### Обратная совместимость

Старые проекты без новых полей будут работать корректно:

```json
{
  "bitrix_id": 100,
  "organization_name": "Старый проект",
  "object_name": "Объект",
  "system_type": "Система",
  "location": "Адрес",
  "implementation_date": "2025-01-01",
  "status": "NEW",
  "client_id": "1"
}
```

При отображении новые поля покажут значения по умолчанию.

### Миграция данных

Существующие проекты автоматически дополняются новыми полями при следующей синхронизации через webhook или скрипт `test_sync.php`.

---

## Тестирование

### Тестовые данные

Для тестирования можно использовать следующие сценарии:

#### 1. Все поля заполнены
```bash
# Создать проект в Bitrix24 со всеми заполненными полями
# Проверить webhook и отображение
```

#### 2. Все поля пустые
```bash
# Создать проект с пустыми новыми полями
# Убедиться, что отображаются дефолтные значения
```

#### 3. Множественные файлы
```bash
# Загрузить несколько файлов в поле "Перечень оборудования"
# Проверить, что все файлы отображаются
```

#### 4. Различные форматы чекбокса
```bash
# Протестировать: Y/N, 1/0, true/false
# Убедиться в корректном преобразовании
```

### Проверка через test_sync.php

```bash
cd /var/www/efrolov-dev/html/application/lk
php src/scripts/test_sync.php [contact_id]
```

Скрипт синхронизирует все проекты контакта с корректной обработкой типов данных.

---

## Заключение

Реализована полная обработка всех типов данных новых полей проекта:

✅ Списочные поля - извлечение ID значения  
✅ Файловые поля - структурированное хранение с метаданными  
✅ Текстовые поля - простое сохранение  
✅ Чекбоксы - универсальное преобразование в boolean  

Система готова к работе с любыми форматами данных от Bitrix24 API.

---

**Версия:** 1.1  
**Дата:** 04.12.2025  
**Статус:** ✅ Полная реализация


