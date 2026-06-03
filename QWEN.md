# QWEN.md — Личный кабинет (Интеграция с Bitrix24)

## Обзор проекта

PHP-приложение для интеграции с **Bitrix24 CRM**. Получает webhook-события от Bitrix24, ставит их в очередь, обрабатывает последовательно и хранит данные локально в JSON-файлах. Отображает личный кабинет клиента с данными из CRM (контакты, компании, проекты, менеджеры).

**Ключевые возможности:**
- Приём и валидация webhook-событий Bitrix24 (контакты, компании, смарт-процессы)
- Асинхронная очередь обработки с гарантией порядка событий по сущности
- Локальное хранение данных (JSON + кэш в памяти)
- Отображение личного кабинета клиента (Bootstrap UI)
- Отправка email через бизнес-процессы Bitrix24
- Интеграция с 1C-Bitrix CMS через `LocalStorage_prod.php`

## Архитектура

### Поток данных

```
Bitrix24 → Webhook → Queue → Worker → Bitrix24 API → Local Storage (JSON)
    ↓         ↓       ↓       ↓           ↓               ↓
  Event     200 OK   Task    Process    Get Data        Sync Data
```

### Ключевые компоненты

| Компонент | Файл | Назначение |
|-----------|------|------------|
| Webhook endpoint | `src/webhooks/bitrix24.php` | Принимает события от Bitrix24, валидирует, помещает в очередь |
| QueueManager | `src/classes/QueueManager.php` | Управляет файлом очереди (JSON), атомарные операции через flock() |
| WebhookProcessor | `src/classes/WebhookProcessor.php` | Логика обработки событий (CREATE/UPDATE/DELETE) с повторными попытками |
| Worker | `src/scripts/process_queue.php` | Фоновый процесс, батчами обрабатывает очередь |
| Bitrix24API | `src/classes/Bitrix24API.php` | REST-клиент для API Bitrix24 (get/list/add/update/delete) |
| LocalStorage | `src/classes/LocalStorage.php` | Локальное хранение в JSON (контакты, компании, проекты, менеджеры) |
| LocalStorage_prod | `src/classes/LocalStorage_prod.php` | Продуктовая версия для работы внутри 1C-Bitrix CMS (highload-блоки) |
| Logger | `src/classes/Logger.php` | Многоуровневое логирование (DEBUG/INFO/WARNING/ERROR) |
| EnvLoader | `src/classes/EnvLoader.php` | Загрузка `.env` переменных |
| Dashboard UI | `index.php` | Главная страница личного кабинета (PHP + Bootstrap 5) |
| Worker restart UI | `restart_worker.php` | Веб-интерфейс перезапуска воркера |

## Структура директорий

```
lk/
├── index.php                      # Главная страница ЛК
├── restart_worker.php             # Веб-интерфейс перезапуска воркера
├── .env.example                   # Шаблон конфигурации
├── .htaccess                      # Apache-конфиг (безопасность, кэширование)
├── QWEN.md                        # Этот файл
├── src/
│   ├── classes/
│   │   ├── Bitrix24API.php        # REST-клиент Bitrix24 API (2466 строк)
│   │   ├── LocalStorage.php       # Локальное JSON-хранилище (940 строк)
│   │   ├── LocalStorage_prod.php  # Продуктовая версия для 1C-Bitrix CMS (1187 строк)
│   │   ├── QueueManager.php       # Менеджер очереди (432 строки)
│   │   ├── WebhookProcessor.php   # Процессор событий (1386 строк)
│   │   ├── Logger.php             # Логирование (128 строк)
│   │   └── EnvLoader.php          # Загрузка .env
│   ├── config/
│   │   ├── bitrix24.php           # Dev-конфигурация (маппинг полей, ID процессов)
│   │   ├── bitrix24_prod.php      # Prod-конфигурация
│   │   └── bitrix24_prod_mini.php # Mini-prod конфигурация
│   ├── webhooks/
│   │   ├── bitrix24.php           # Webhook endpoint (с очередью)
│   │   └── webhook_old.php        # Старая версия (без очереди)
│   ├── scripts/
│   │   ├── process_queue.php      # Фоновый воркер обработки очереди
│   │   ├── restart_worker.sh      # Bash-скрипт перезапуска воркера
│   │   ├── tests/                 # Тестовые PHP-скрипты (gitignored)
│   │   ├── README_MULTIPLE_FILES.md  # Документация по тестированию
│   │   └── README_get_company_inn.md
│   ├── data/                      # JSON-файлы данных (создаются автоматически)
│   │   ├── contacts.json
│   │   ├── companies.json
│   │   ├── projects.json
│   │   ├── managers.json
│   │   ├── webhook_queue.json
│   │   └── worker.pid
│   └── logs/
│       └── bitrix24_webhooks.log  # Лог приложения
└── docs/                          # Документация
    ├── README_QUEUE_SYSTEM.md     # Документация системы очередей
    ├── first_auth_setup.md        # Настройка БП 614 при первой авторизации
    ├── bitrix24_email_setup.md    # Настройка email
    ├── Requirements.md            # Требования к интеграции
    ├── email_templates/           # HTML-шаблоны писем
    └── ...
```

## Технологический стек

- **PHP 7.4+** (без Composer, без фреймворков — чистый PHP с `require_once`)
- **Apache/Nginx** — веб-сервер
- **Bootstrap 5** + **Font Awesome 6** — фронтенд (CDN)
- **JSON-файлы** — локальное хранилище (dev/тестирование)
- **Highload-блоки** — продакшен-хранилище через LocalStorage_prod (в среде 1C-Bitrix)
- **Bitrix24 REST API** — внешнее CRM-API

## Конфигурация

### Переменные окружения (`.env`)

```env
BITRIX24_WEBHOOK_URL=https://portal.bitrix24.ru/rest/1/token/
BITRIX24_APPLICATION_TOKEN=token
BITRIX24_TIMEOUT=30
LOG_ENABLED=true
LOG_LEVEL=INFO
```

### Центральный конфиг (`src/config/bitrix24.php`)

Содержит:
- **ID смарт-процессов**: проекты (1142), изменение данных (1152), удаление данных (1164)
- **ID бизнес-процессов**: email (556), первая авторизация (614)
- **Маппинг полей**: контакты, компании, смарт-процессы, пользователи
- **Настройки очереди**: batch_size (10), max_attempts (3), idle_sleep_time (30s)
- **Настройки событий**: enabled_events, retry_delays [5, 30, 300, 3600]
- Пути к файлам данных и логов

Для продакшена используются конфиги `bitrix24_prod.php` / `bitrix24_prod_mini.php`.

## Запуск и обслуживание

### Запуск воркера

```bash
cd /var/www/efrolov-dev/html/application/lk
php src/scripts/process_queue.php &
./src/scripts/restart_worker.sh
```

Веб-интерфейс: открыть `/restart_worker.php` в браузере.

### Проверка статуса воркера

```bash
ps aux | grep process_queue.php
cat src/data/worker.pid
```

### Просмотр статистики очереди

```bash
php -r "
require 'src/classes/QueueManager.php';
require 'src/classes/Logger.php';
\$c = require 'src/config/bitrix24.php';
\$qm = new QueueManager(new Logger(\$c), \$c);
print_r(\$qm->getStats());
"
```

### Логи

```bash
tail -f src/logs/bitrix24_webhooks.log
grep "ERROR\|FATAL" src/logs/bitrix24_webhooks.log
```

### Настройка webhook в Bitrix24

URL: `https://your-domain.com/src/webhooks/bitrix24.php`
События: `ONCRMCONTACTADD/UPDATE/DELETE`, `ONCRMCOMPANYADD/UPDATE/DELETE`, `ONCRMDYNAMICITEMADD/UPDATE/DELETE`

## Тестирование

- **Нет автоматизированного test runner** — все тесты ручные PHP-скрипты в `src/scripts/tests/` (gitignored)
- Запуск: `php src/scripts/test_<name>.php`
- Основные сценарии тестирования описаны в `src/scripts/README_MULTIPLE_FILES.md`
- Для стресс-тестирования очереди: `php src/scripts/simple_stress_test.php 50 5`

## Конвенции разработки

### Код-стайл

- PHP без фреймворков, всё через `require_once` для автозагрузки
- PSR-подобный: фигурные скобки на новой строке для классов, на той же для методов
- PHPDoc-комментарии для публичных методов
- `PascalCase` для классов, `camelCase` для методов и свойств
- **Комментарии на русском** — весь проект ориентирован на русскоязычных разработчиков
- Файлы конфигурации с суффиксом `_prod` — для продакшен-среды

### Структура классов

- Конструктор принимает `$config` (array) и `$logger`
- Конфиг — ассоциативный массив из `bitrix24.php`
- Логгер обязателен для всех классов
- Обработка ошибок: исключения + `$logger->error()`
- Кэширование в `$cachedData` (память) для LocalStorage

### Правила обработки сущностей

| Сущность | Условие создания | Привязка |
|----------|-----------------|----------|
| **Контакты** | Поле `UF_CRM_1763468430` = 120 (ЛК клиента) | по `bitrix_id` |
| **Компании** | `CONTACT_ID` указан и контакт существует | по `bitrix_id` |
| **Проекты** | Контакт существует | по `contactId` |
| **Удаление** | Поле ЛК контакта = 118 | данные удаляются |

### Безопасность

- `.env` защищён через `.htaccess` (Deny from all)
- `logs/` защищена от прямого доступа
- Webhook-валидация: User-Agent (Bitrix24), `application_token` (если задан)
- Лимит тела запроса: 10MB (HTTP 413 при превышении)
- Атомарные операции с файлом очереди через `flock()`
- PID-файл предотвращает множественные экземпляры воркера
- Автоопределение Content-Type (JSON/URL-encoded)

## Важные замечания

1. **Два режима LocalStorage**:
   - `LocalStorage.php` — JSON-файлы (dev, изолированное тестирование)
   - `LocalStorage_prod.php` — работа внутри 1C-Bitrix CMS с highload-блоками (production)
   - Оба реализуют одинаковый интерфейс, подмена через `require`

2. **Worker без перезапуска**: функционал, работающий напрямую из ini-файлов (без изменения PHP-кода), не требует перезапуска воркера.

3. **Бизнес-процессы в Bitrix24**:
   - **БП 614** — запускается при первой авторизации пользователя на сайте (OnAfterUserAuthorize)
   - **БП 556** — отправка email контакту при создании ЛК
   - Интеграция через `local/php_interface/init.php` на стороне 1C-Bitrix

4. **Связь пользователя ↔ контакт в CRM**: `XML_ID` пользователя на сайте = ID контакта в облачном Bitrix24. Менеджеры имеют префикс `USER_` — исключаются проверкой.

5. **Три профиля конфигурации**: `bitrix24.php` (dev), `bitrix24_prod.php` (prod), `bitrix24_prod_mini.php` (mini-prod). Выбор зависит от среды.
