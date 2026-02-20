# Система последовательной обработки вебхуков Bitrix24

## Обзор

Система реализована для решения проблемы **race conditions** при одновременном получении событий создания (CREATE) и обновления (UPDATE) сущностей из Bitrix24. Теперь все вебхуки сначала помещаются в очередь и обрабатываются последовательно по каждой сущности, гарантируя порядок операций.

## Архитектура

### Основные компоненты

1. **QueueManager** (`src/classes/QueueManager.php`)
   - Управляет файлом очереди (`data/webhook_queue.json`)
   - Обеспечивает атомарные операции чтения/записи
   - Группирует задачи по `entity_id` для последовательной обработки

2. **WebhookProcessor** (`src/classes/WebhookProcessor.php`)
   - Содержит всю логику обработки событий
   - Работает с Bitrix24 API для получения данных
   - Синхронизирует данные с локальным хранилищем

3. **Воркер** (`src/scripts/process_queue.php`)
   - Фоновый процесс обработки очереди
   - Обрабатывает задачи батчами по 10 штук
   - Гарантирует последовательность обработки

4. **Вебхук** (`src/webhooks/bitrix24.php`)
   - Принимает запросы от Bitrix24
   - Немедленно помещает их в очередь
   - Возвращает ответ 200 OK за ~100мс

### Принцип работы

```
Bitrix24 → Webhook → Queue → Worker → Bitrix24 API → Local Storage
     ↓         ↓       ↓       ↓         ↓           ↓
  Event     200 OK   Task   Process   Get Data   Sync Data
```

## Установка и настройка

### 1. Файлы системы

Все необходимые файлы уже созданы в следующих директориях:

```
src/
├── classes/
│   ├── QueueManager.php      # Менеджер очереди
│   └── WebhookProcessor.php  # Обработчик событий
├── scripts/
│   └── process_queue.php     # Воркер обработки
└── webhooks/
    └── bitrix24.php          # Модифицированный вебхук

data/
├── webhook_queue.json        # Файл очереди
└── worker.pid               # PID файл воркера

logs/
└── bitrix24_webhooks.log    # Логи системы
```

### 2. Конфигурация

Основные настройки в `src/config/bitrix24.php`:

```php
'queue' => [
    'batch_size' => 10,        // Задач за одну итерацию
    'max_attempts' => 3,       // Максимум попыток обработки
    'idle_sleep_time' => 30,   // Пауза при пустой очереди (сек)
    'queue_file' => __DIR__ . '/../data/webhook_queue.json',
    'pid_file' => __DIR__ . '/../data/worker.pid',
],
```

## Запуск системы

### 1. Запуск воркера

```bash
cd /var/www/efrolov-dev/html/application/lk/src/scripts
php process_queue.php &
```

Воркер запустится в фоне и будет:
- Проверять очередь каждые 30 секунд
- Обрабатывать по 10 задач за итерацию
- Группировать задачи по `entity_id`
- Обрабатывать события последовательно

### 2. Проверка статуса

```bash
# Статус очереди
cd src/scripts
php -r "
require_once '../classes/QueueManager.php';
require_once '../classes/Logger.php';
$config = require '../config/bitrix24.php';
$logger = new Logger($config);
$qm = new QueueManager($logger, $config);
print_r($qm->getStats());
"

# Статус воркера
ps aux | grep process_queue.php
cat data/worker.pid  # PID воркера
```

### 3. Перезапуск воркера

Для удобного перезапуска воркера используйте скрипт `src/scripts/restart_worker.sh`:

```bash
# Из корневой директории проекта
./src/scripts/restart_worker.sh

# Показать справку
./src/scripts/restart_worker.sh --help
```

**Что делает скрипт:**
- Проверяет работу существующего воркера
- Корректно останавливает процесс (SIGTERM)
- Ждет завершения процесса (до 30 секунд)
- Запускает новый экземпляр
- Проверяет успешный запуск

### 4. Веб-интерфейс управления

Для быстрого перезапуска воркера через браузер используйте простой веб-интерфейс:

```bash
# Доступен по адресу:
http://ваш-сайт/restart_worker.php
```

**Функции веб-интерфейса:**
- 🔄 **Перезапуск воркера** одной кнопкой
- 📊 **Отображение результата** выполнения скрипта
- ⚡ **Мгновенное выполнение** без лишних настроек

**Как использовать:**
1. Откройте `http://ваш-сайт/restart_worker.php` в браузере
2. Нажмите кнопку "🚀 Перезапустить воркер"
3. Дождитесь выполнения и посмотрите результат

### 5. Ручная остановка воркера

```bash
# Корректная остановка
kill $(cat data/worker.pid)

# Или принудительно
pkill -f process_queue.php
```

## Мониторинг

### Логи

Все события логируются в `src/logs/bitrix24_webhooks.log`:

```bash
# Последние события
tail -f src/logs/bitrix24_webhooks.log

# Статистика обработки
grep "Task processed successfully" src/logs/bitrix24_webhooks.log | wc -l
grep "Queue processing completed" src/logs/bitrix24_webhooks.log | tail -5
```

### Метрики производительности

```bash
# Скорость обработки (запросов в секунду)
grep "Task processed successfully" logs/bitrix24_webhooks.log | \
  awk -F'[][]' '{print $2}' | \
  date -f - +%s | \
  awk 'NR==1{min=$1} {sum+=$1} END{print (NR-1)/(sum/NR-min)}'
```

### Проверка здоровья системы

```bash
#!/bin/bash
# health_check.sh

# Проверка воркера
if ! pgrep -f "process_queue.php" > /dev/null; then
    echo "ERROR: Worker not running"
    exit 1
fi

# Проверка размера очереди
QUEUE_SIZE=$(wc -c < data/webhook_queue.json)
if [ $QUEUE_SIZE -gt 1000000 ]; then  # 1MB
    echo "WARNING: Large queue file ($QUEUE_SIZE bytes)"
fi

# Проверка логов на ошибки
ERRORS=$(grep -c "ERROR\|FATAL" logs/bitrix24_webhooks.log)
if [ $ERRORS -gt 0 ]; then
    echo "WARNING: $ERRORS errors in logs"
fi

echo "System OK"
```

## Тестирование

### Стресс-тест

```bash
cd src/scripts

# Простой тест (50 запросов, 5 параллельных)
php simple_stress_test.php 50 5

# Интенсивный тест (500 запросов, 20 параллельных)
php simple_stress_test.php 500 20
```

### Ручное тестирование

```bash
# Отправка тестового вебхука
curl -X POST \
  -H "Content-Type: application/json" \
  -H "User-Agent: Bitrix24 Webhook Engine" \
  -d '{
    "event": "ONCRMCONTACTUPDATE",
    "data": {"FIELDS": {"ID": "123"}},
    "auth": {"application_token": "ваш_токен"}
  }' \
  https://ваш-сайт.ru/application/lk/src/webhooks/bitrix24.php
```

## Особенности работы

### Группировка задач

Задачи группируются по `entity_id` и обрабатываются последовательно:

```
Entity ID: 123
├── ONCRMCONTACTADD (ts: 1000)
├── ONCRMCONTACTUPDATE (ts: 1001)  ← обрабатывается вторым
└── ONCRMCONTACTUPDATE (ts: 1002)  ← обрабатывается третьим
```

### Обработка ошибок

- **API недоступен**: задача повторяется до 3 раз с задержками 5с, 30с, 5мин
- **Сущность не найдена**: считается успешным завершением (удаление из ЛК)
- **Недостаточно прав**: логируется предупреждение, обработка продолжается

### Безопасность

- Атомарные операции с файлом очереди через `flock()`
- PID-файл предотвращает запуск нескольких воркеров
- Валидация токенов приложений
- Защита от переполнения очереди

## Производительность

### Результаты тестирования

```
Малый тест (20 запросов):
├── Время: 0.582s
├── RPS: 34.37
├── Успешность: 100%
└── Среднее время ответа: 0.103s

Большой тест (500 запросов):
├── Время: 14.529s
├── RPS: 34.41
├── Успешность: 100%
└── Среднее время ответа: 0.409s
```

### Ограничения

- **Память**: воркер использует ~2MB RAM
- **CPU**: нагрузка ~1-2% при обработке
- **Диск**: очередь хранится в JSON файле
- **Сеть**: зависит от скорости Bitrix24 API

## Обслуживание

### Резервное копирование

```bash
# Архивация данных
tar -czf backup_$(date +%Y%m%d).tar.gz \
  data/contacts.json \
  data/companies.json \
  data/projects.json \
  data/managers.json
```

### Очистка

```bash
# Очистка старых логов
find logs/ -name "*.log" -mtime +30 -delete

# Очистка завершенных задач
cd src/scripts
php -r "
\$qm = new QueueManager(new Logger(require '../config/bitrix24.php'), require '../config/bitrix24.php');
echo 'Cleared: ' . \$qm->clearProcessed() . ' tasks\n';
"
```

### Обновление

1. Остановить воркер
2. Сделать бэкап данных
3. Заменить файлы
4. Проверить конфигурацию
5. Запустить воркер

## Troubleshooting

### Воркер не запускается

```bash
# Проверить права на файлы
ls -la data/ logs/

# Проверить PHP ошибки
php -l src/scripts/process_queue.php

# Проверить конфигурацию
php -r "require 'src/config/bitrix24.php'; echo 'Config OK\n';"
```

### Очередь не очищается

```bash
# Проверить статус задач
php -r "
\$qm = new QueueManager(new Logger(require 'src/config/bitrix24.php'), require 'src/config/bitrix24.php');
print_r(\$qm->getStats());
"

# Ручная очистка
php -r "
\$qm = new QueueManager(new Logger(require 'src/config/bitrix24.php'), require 'src/config/bitrix24.php');
echo \$qm->clearProcessed() . ' tasks cleared\n';
"
```

### Высокая нагрузка на API

```bash
# Увеличить задержки между запросами
# В config/bitrix24.php изменить min_api_interval

'bitrix24' => [
    'min_api_interval' => 1.0,  // 1 секунда между запросами
],
```

## Заключение

Система обеспечивает надежную последовательную обработку вебхуков, предотвращая race conditions и гарантируя консистентность данных. Производительность позволяет обрабатывать сотни запросов в минуту при сохранении стабильности работы.