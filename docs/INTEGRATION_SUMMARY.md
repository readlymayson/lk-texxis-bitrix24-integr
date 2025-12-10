# Интеграция: проекты и смарт‑процессы

**Дата:** 10.12.2025  
**Статус:** ✅ Актуально

## Проекты (смарт‑процесс)

### Поля и ID Bitrix24
| Поле | ID | Тип |
|------|----|-----|
| Тип запросов | `ufCrm6_1765128261855` | Select |
| Перечень оборудования | `ufCrm6_1765299780` | File |
| Возможные конкуренты | `ufCrm6_1765128315359` | Text |
| Маркетинговая скидка | `ufCrm6_1765128343798` | Checkbox |
| Техническое описание | `ufCrm6_1765360431193` | Textarea |

### Интеграция
- Конфиг: `src/config/bitrix24.php` — маппинг полей в секции `smart_process`.
- Обработка: `src/webhooks/bitrix24.php` (`mapProjectData`) и `src/scripts/test_sync.php`.
- Хранение: `src/classes/LocalStorage.php` (add/sync projects, хранение файлов, множ. system_types).
- Отображение: `index.php` — колонки для всех полей, файлы со ссылками, чекбокс в бейджах.

### Проверка интеграции (чек‑лист)
- Маппинг полей в конфиге настроен (5 полей: request_type, equipment_list, competitors, marketing_discount, technical_description).
- Webhook обрабатывает поля с дефолтами: `request_type` (string), `equipment_list` (null/массив), `competitors` (string), `marketing_discount` (bool), `technical_description` (string).
- LocalStorage сохраняет/синхронизирует новые поля; данные в `data/projects.json`.
- Тесты: `php src/scripts/test_sync.php [contact_id]` — поля приезжают и отображаются в ЛК.

### Ограничения
- `Тип запросов` выводится как значение ID; для меток нужен доп. запрос к Bitrix24 (декодер не реализован).

### Следующие шаги
- Добавить декодер списочных полей (`request_type`).
- Опционально: редактирование проектов из ЛК.

## Смарт‑процессы: изменение/удаление данных ЛК
- ID смарт‑процессов настроены в `src/config/bitrix24.php`:  
  - `smart_process_change_data_id` → 1042 (изменение данных)  
  - `smart_process_delete_data_id` → 1046 (удаление данных)
- Маппинг полей: `smart_process_change_data`, `smart_process_delete_data` в конфиге.
- Использование:
  - Создание карточек через `Bitrix24API::createChangeDataCard` / `createDeleteDataCard`.
  - Точки вызова: отправка запросов на изменение личных/компаний данных и удаление ЛК.
- Логи: в `src/logs/bitrix24_webhooks.log`.

## Документация
- Анализ полей: `docs/fields_analysis.md`.
- Остальные подробности объединены в этот файл; отдельные журналы/чек‑листы по проектам свернуты.

