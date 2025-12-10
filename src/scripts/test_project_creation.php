<?php

/**
 * Скрипт для тестирования создания карточек проектов в смарт-процессе
 * 
 * Использование:
 * php test_project_creation.php [contact_id] [file_id]
 * 
 * Примеры:
 * php test_project_creation.php 3                    # Создание проекта для контакта ID=3 (файл будет загружен автоматически)
 * php test_project_creation.php 3 123                 # Использовать существующий файл с ID=123
 * 
 * Примечание: 
 * - company_id и manager_id автоматически берутся из базы данных ЛК по contact_id
 * - file_id (опционально) - ID уже загруженного файла в Bitrix24. Если не указан, будет создан и загружен тестовый файл
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($config, $logger);

$contactId = $argv[1] ?? null;
$fileId = $argv[2] ?? null; // Опциональный ID файла для использования

echo "=== ТЕСТИРОВАНИЕ СОЗДАНИЯ ПРОЕКТА ===\n\n";

if (empty($contactId)) {
    echo "Введите параметры для тестирования:\n";
    echo "Contact ID (обязательно): ";
    $contactId = trim(fgets(STDIN));
    
    if (empty($contactId)) {
        echo "\nОШИБКА: Не указан contact_id (ID контакта)\n";
        echo "Использование: php test_project_creation.php <contact_id> [file_id]\n";
        echo "Пример: php test_project_creation.php 3\n";
        echo "\nПримечание: company_id и manager_id автоматически берутся из базы данных ЛК\n";
        exit(1);
    }
    
    echo "\n";
}

echo "Параметры:\n";
echo "  Contact ID: {$contactId}\n";
echo "  Company ID: будет получен из базы ЛК\n";
echo "  Manager ID: будет получен из Bitrix24 API\n\n";

$projectProcessId = $config['bitrix24']['smart_process_id'] ?? '';

echo "Конфигурация:\n";
echo "  Smart Process Projects ID: " . ($projectProcessId ?: 'НЕ НАСТРОЕН') . "\n\n";

if (empty($projectProcessId)) {
    echo "ОШИБКА: ID смарт-процесса проектов не настроен в конфигурации!\n";
    echo "Проверьте файл: src/config/bitrix24.php\n";
    exit(1);
}

// Получаем поля типа списка смарт-процесса
echo "--- ПОЛЯ ТИПА СПИСКА СМАРТ-ПРОЦЕССА ---\n";
$listFields = $bitrixAPI->getSmartProcessListFields($projectProcessId);

// Убеждаемся, что $listFields всегда является массивом
if (!is_array($listFields)) {
    $listFields = [];
}

if (!empty($listFields)) {
    echo "Найдено полей типа списка: " . count($listFields) . "\n\n";
    
    foreach ($listFields as $fieldId => $fieldInfo) {
        echo "Поле: {$fieldInfo['name']} (ID: {$fieldId})\n";
        echo "  Тип: {$fieldInfo['type']}\n";
        
        if (!empty($fieldInfo['values'])) {
            echo "  Значения списка:\n";
            foreach ($fieldInfo['values'] as $valueId => $valueName) {
                echo "    - ID: {$valueId} => {$valueName}\n";
            }
        } else {
            echo "  ⚠ Значения списка не найдены\n";
        }
        echo "\n";
    }
} else {
    echo "⚠ Не удалось получить поля типа списка или список пуст\n";
    echo "  Проверьте логи для детальной информации\n";
    echo "\n";
}

// Получаем данные контакта из локального хранилища
$contact = $localStorage->getContact($contactId);
$companyId = $contact['company'] ?? null;
$managerId = $contact['manager_id'] ?? null;

echo "--- СОЗДАНИЕ КАРТОЧКИ ПРОЕКТА ---\n";

$mapping = $config['field_mapping']['smart_process'] ?? [];
$projectFields = [];

// Обязательные привязки
if (!empty($mapping['client_id'])) {
    $projectFields[$mapping['client_id']] = $contactId;
}
if (!empty($managerId) && !empty($mapping['manager_id'])) {
    $projectFields[$mapping['manager_id']] = $managerId;
}

// Функция для получения первого значения поля типа списка
$getFirstListValue = function($fieldId, $listFields) {
    if (isset($listFields[$fieldId]) && !empty($listFields[$fieldId]['values'])) {
        $values = $listFields[$fieldId]['values'];
        // Получаем первое значение из массива
        $firstValueId = array_key_first($values);
        if ($firstValueId !== null) {
            return [
                'id' => $firstValueId,
                'name' => $values[$firstValueId]
            ];
        }
    }
    return null;
};

// Тестовые данные проекта
if (!empty($mapping['organization_name'])) {
    $projectFields[$mapping['organization_name']] = 'Тестовая организация';
}
if (!empty($mapping['object_name'])) {
    $projectFields[$mapping['object_name']] = 'Тестовый объект';
}
if (!empty($mapping['system_types'])) {
    // Поле множественное - используем несколько первых значений из списка
    $systemTypesValues = [];
    if (!empty($listFields[$mapping['system_types']]) && !empty($listFields[$mapping['system_types']]['values'])) {
        $values = $listFields[$mapping['system_types']]['values'];
        // Берем первые 2-3 значения для теста
        $count = 0;
        foreach ($values as $valueId => $valueName) {
            if ($count >= 2) break; // Берем максимум 2 значения
            $systemTypesValues[] = $valueId;
            $count++;
        }
        if (!empty($systemTypesValues)) {
            $projectFields[$mapping['system_types']] = $systemTypesValues;
            $names = [];
            foreach ($systemTypesValues as $valId) {
                $names[] = $values[$valId] ?? $valId;
            }
            echo "  Поле 'system_types': используется " . count($systemTypesValues) . " значения из списка (ID: " . implode(', ', $systemTypesValues) . ", названия: " . implode(', ', $names) . ")\n";
        }
    }
    
    // Если не удалось получить из списка, используем тестовое значение
    if (empty($systemTypesValues)) {
        $projectFields[$mapping['system_types']] = ['Система безопасности'];
        echo "  Поле 'system_types': используется тестовое значение (список не найден)\n";
    }
}
if (!empty($mapping['location'])) {
    $projectFields[$mapping['location']] = 'Москва, ул. Пример, д.1';
}
if (!empty($mapping['technical_description'])) {
    $projectFields[$mapping['technical_description']] = "Техническое описание проекта для теста:\n- Система пожарной сигнализации\n- Узлы сопряжения и автоматика";
}
if (!empty($mapping['implementation_date'])) {
    $projectFields[$mapping['implementation_date']] = date('Y-m-d');
}
if (!empty($mapping['request_type'])) {
    // Используем первое значение из списка, если поле является списком
    $firstValue = $getFirstListValue($mapping['request_type'], $listFields);
    if ($firstValue) {
        $projectFields[$mapping['request_type']] = $firstValue['id'];
        echo "  Поле 'request_type': используется первое значение списка (ID: {$firstValue['id']}, название: {$firstValue['name']})\n";
    } else {
        // Если не удалось получить из списка, используем старое значение
        $projectFields[$mapping['request_type']] = 'test_request';
        echo "  Поле 'request_type': используется тестовое значение (список не найден)\n";
    }
}
if (!empty($mapping['equipment_list'])) {
    // Для передачи файла в Bitrix24 нужен массив ID файлов
    $finalFileId = null;
    $testFilePath = __DIR__ . '/test_equipment_list.txt';
    
    if (!empty($fileId)) {
        // Используем указанный ID файла
        $finalFileId = (int)$fileId;
        echo "Использование указанного файла ID: {$finalFileId}\n";
    } else {
        // Всегда используем тестовый файл
        if (!file_exists($testFilePath)) {
            echo "✗ ОШИБКА: Тестовый файл не найден: {$testFilePath}\n";
            echo "  Создайте файл вручную или проверьте путь\n\n";
        } else {
            $fileSize = filesize($testFilePath);
            echo "Найден тестовый файл: {$testFilePath} (размер: {$fileSize} байт)\n";
            echo "Загрузка файла в Bitrix24...\n";
            
            $uploadResult = $bitrixAPI->uploadFile($testFilePath);
            
            // Детальная диагностика результата загрузки
            if ($uploadResult === false) {
                echo "✗ ОШИБКА: Метод uploadFile вернул false\n";
                echo "  Проверьте логи для детальной информации\n";
            } elseif (is_array($uploadResult)) {
                echo "  Результат загрузки: " . json_encode($uploadResult, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                
                if (isset($uploadResult['id'])) {
                    $finalFileId = $uploadResult['id'];
                    echo "✓ Файл загружен успешно, ID: {$finalFileId}\n";
                    echo "  Имя файла: " . ($uploadResult['name'] ?? basename($testFilePath)) . "\n";
                    if (isset($uploadResult['size'])) {
                        echo "  Размер: {$uploadResult['size']} байт\n";
                    }
                } else {
                    echo "✗ ОШИБКА: В результате загрузки отсутствует ID файла\n";
                    echo "  Структура ответа: " . json_encode($uploadResult, JSON_UNESCAPED_UNICODE) . "\n";
                }
            } else {
                echo "✗ ОШИБКА: Неожиданный тип результата: " . gettype($uploadResult) . "\n";
                echo "  Значение: " . var_export($uploadResult, true) . "\n";
            }
            
            if (!$finalFileId) {
                echo "\n  ДИАГНОСТИКА:\n";
                echo "  1. Проверьте настройки webhook URL в конфигурации\n";
                echo "  2. Убедитесь, что у webhook есть права на загрузку файлов (disk)\n";
                echo "  3. Проверьте логи в файле: " . $config['logging']['file'] . "\n";
                echo "  4. Убедитесь, что файл существует и доступен для чтения\n";
                echo "\n  АЛЬТЕРНАТИВА: Укажите ID уже загруженного файла через параметр:\n";
                echo "  php test_project_creation.php {$contactId} <file_id>\n\n";
            }
        }
    }
    
    if ($finalFileId) {
        // Поле equipment_list типа "Ссылка" - используем полную внутреннюю ссылку на файл
        // Получаем внутреннюю ссылку из результата загрузки
        $internalLink = null;
        if (is_array($uploadResult) && isset($uploadResult['internal_link'])) {
            $internalLink = $uploadResult['internal_link'];
        }
        
        if ($internalLink) {
            $projectFields[$mapping['equipment_list']] = $internalLink;
            echo "  ✓ Файл (ID: {$finalFileId}) будет прикреплен к проекту как внутренняя ссылка\n";
            echo "  Ссылка: {$internalLink}\n\n";
        } else {
            // Если внутренняя ссылка не получена, используем формат disk_file_<ID> как запасной вариант
            $projectFields[$mapping['equipment_list']] = 'disk_file_' . $finalFileId;
            echo "  ✓ Файл (ID: {$finalFileId}) будет прикреплен к проекту (используется формат disk_file_{$finalFileId})\n\n";
        }
    } else {
        echo "  ⚠ ВНИМАНИЕ: Поле equipment_list не будет заполнено (файл не загружен)\n";
        echo "  Проект будет создан без файла в поле 'Перечень оборудования'\n\n";
    }
}
if (!empty($mapping['competitors'])) {
    $projectFields[$mapping['competitors']] = 'Конкурент А; Конкурент Б';
}
if (!empty($mapping['marketing_discount'])) {
    $projectFields[$mapping['marketing_discount']] = true;
}
if (!empty($mapping['status'])) {
    // Используем первое значение из списка, если поле является списком
    $firstValue = $getFirstListValue($mapping['status'], $listFields);
    if ($firstValue) {
        $projectFields[$mapping['status']] = $firstValue['id'];
        echo "  Поле 'status': используется первое значение списка (ID: {$firstValue['id']}, название: {$firstValue['name']})\n";
    } else {
        // Если не удалось получить из списка, используем старое значение
        $projectFields[$mapping['status']] = 'DT123_1:NEW';
        echo "  Поле 'status': используется тестовое значение (список не найден)\n";
    }
}

echo "Данные для создания карточки проекта:\n";
echo json_encode($projectFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

echo "Отправка запроса...\n";
$result = $bitrixAPI->addSmartProcessItem($projectProcessId, $projectFields);

if ($result && isset($result['id'])) {
    echo "✓ УСПЕХ: Карточка проекта создана!\n";
    echo "  Card ID: {$result['id']}\n";
    if (isset($result['title'])) {
        echo "  Title: {$result['title']}\n";
    }
    echo "\n";
} else {
    echo "✗ ОШИБКА: Не удалось создать карточку проекта\n";
    if (is_array($result)) {
        echo "  Response: " . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    } else {
        echo "  Response: " . var_export($result, true) . "\n";
    }
    echo "\n";
}

echo "=== ТЕСТИРОВАНИЕ ЗАВЕРШЕНО ===\n";
echo "Проверьте логи в файле: " . $config['logging']['file'] . "\n";

