<?php

/**
 * Скрипт для тестирования создания карточек проектов в смарт-процессе
 * 
 * Использование:
 * php test_project_creation.php [contact_id] [file_id1] [file_id2] ... [file_idN]
 * php test_project_creation.php [contact_id] --files [file_path1] [file_path2] ... [file_pathN]
 * 
 * Примеры:
 * php test_project_creation.php 3                    # Создание проекта для контакта ID=3 (файл будет загружен автоматически)
 * php test_project_creation.php 3 123                 # Использовать существующий файл с ID=123
 * php test_project_creation.php 3 123 456 789         # Использовать несколько существующих файлов с ID=123, 456, 789
 * php test_project_creation.php 3 --files file1.txt file2.txt file3.txt  # Загрузить несколько файлов
 * 
 * Примечание: 
 * - company_id и manager_id автоматически берутся из базы данных ЛК по contact_id
 * - file_id (опционально) - ID уже загруженного файла в Bitrix24. Можно указать несколько через пробел
 * - --files - флаг для указания путей к файлам для загрузки (можно указать несколько)
 * - Если не указаны file_id и --files, будет создан и загружен один тестовый файл
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
require_once __DIR__ . '/../classes/Logger.php';
require_once __DIR__ . '/../classes/Bitrix24API.php';
require_once __DIR__ . '/../classes/LocalStorage.php';

// Вспомогательная функция для проверки абсолютного пути
function is_absolute_path($path) {
    return (strpos($path, '/') === 0) || // Unix
           (preg_match('/^[A-Z]:\\\\/', $path)) || // Windows
           (strpos($path, '\\\\') === 0); // UNC Windows
}

$config = require_once __DIR__ . '/../config/bitrix24.php';

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger, $config);

$contactId = $argv[1] ?? null;
$fileIds = [];
$filePaths = [];
$useFilesFlag = false;

// Парсим аргументы командной строки
if (count($argv) > 2) {
    for ($i = 2; $i < count($argv); $i++) {
        if ($argv[$i] === '--files') {
            $useFilesFlag = true;
            continue;
        }
        
        if ($useFilesFlag) {
            // После флага --files все аргументы - это пути к файлам
            $filePaths[] = $argv[$i];
        } else {
            // До флага --files все аргументы - это ID файлов
            $fileIds[] = $argv[$i];
        }
    }
}

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
echo "  Manager ID: будет получен из базы ЛК\n";
if (!empty($fileIds)) {
    echo "  File IDs: " . implode(', ', $fileIds) . "\n";
}
if (!empty($filePaths)) {
    echo "  File Paths: " . implode(', ', $filePaths) . "\n";
}
if (empty($fileIds) && empty($filePaths)) {
    echo "  Files: будет загружен тестовый файл автоматически\n";
}
echo "\n";

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

echo "--- СОЗДАНИЕ КАРТОЧКИ ПРОЕКТА ---\n";
echo "  Contact ID: {$contactId}\n";
echo "  Company ID и Manager ID будут получены автоматически через createProjectCard\n\n";

$mapping = $config['field_mapping']['smart_process'] ?? [];

// Функция для получения первого значения поля типа списка
$getFirstListValue = function($fieldId, $listFields) {
    if (isset($listFields[$fieldId]) && !empty($listFields[$fieldId]['values'])) {
        $values = $listFields[$fieldId]['values'];
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

// Подготовка тестовых данных для формы (ключи по маппингу)
$formFields = [];

// Тестовые данные проекта
if (!empty($mapping['organization_name'])) {
    $formFields['organization_name'] = 'Тестовая организация';
}
if (!empty($mapping['object_name'])) {
    $formFields['object_name'] = 'Тестовый объект';
}
if (!empty($mapping['system_types'])) {
    // Поле множественное - используем несколько первых значений из списка
    $systemTypesValues = [];
    if (!empty($listFields[$mapping['system_types']]) && !empty($listFields[$mapping['system_types']]['values'])) {
        $values = $listFields[$mapping['system_types']]['values'];
        $count = 0;
        foreach ($values as $valueId => $valueName) {
            if ($count >= 2) break;
            $systemTypesValues[] = $valueId;
            $count++;
        }
        if (!empty($systemTypesValues)) {
            $formFields['system_types'] = $systemTypesValues;
            $names = [];
            foreach ($systemTypesValues as $valId) {
                $names[] = $values[$valId] ?? $valId;
            }
            echo "  Поле 'system_types': используется " . count($systemTypesValues) . " значения из списка (ID: " . implode(', ', $systemTypesValues) . ", названия: " . implode(', ', $names) . ")\n";
        }
    }
    
    if (empty($systemTypesValues)) {
        $formFields['system_types'] = ['Система безопасности'];
        echo "  Поле 'system_types': используется тестовое значение (список не найден)\n";
    }
}
if (!empty($mapping['location'])) {
    $formFields['location'] = 'Москва, ул. Пример, д.1';
}
if (!empty($mapping['technical_description'])) {
    $formFields['technical_description'] = "Техническое описание проекта для теста:\n- Система пожарной сигнализации\n- Узлы сопряжения и автоматика";
}
if (!empty($mapping['implementation_date'])) {
    $formFields['implementation_date'] = date('Y-m-d');
}
if (!empty($mapping['request_type'])) {
    $firstValue = $getFirstListValue($mapping['request_type'], $listFields);
    if ($firstValue) {
        $formFields['request_type'] = $firstValue['id'];
        echo "  Поле 'request_type': используется первое значение списка (ID: {$firstValue['id']}, название: {$firstValue['name']})\n";
    } else {
        $formFields['request_type'] = 'test_request';
        echo "  Поле 'request_type': используется тестовое значение (список не найден)\n";
    }
}
if (!empty($mapping['competitors'])) {
    $formFields['competitors'] = 'Конкурент А; Конкурент Б';
}
if (!empty($mapping['marketing_discount'])) {
    $formFields['marketing_discount'] = true;
}
if (!empty($mapping['status'])) {
    $firstValue = $getFirstListValue($mapping['status'], $listFields);
    if ($firstValue) {
        $formFields['status'] = $firstValue['id'];
        echo "  Поле 'status': используется первое значение списка (ID: {$firstValue['id']}, название: {$firstValue['name']})\n";
    } else {
        $formFields['status'] = 'DT123_1:NEW';
        echo "  Поле 'status': используется тестовое значение (список не найден)\n";
    }
}

// Обработка файлов для equipment_list
// createProjectCard сам обработает файлы, нам нужно только подготовить параметры
$fileIdParam = null;
$filePathParam = null;

if (!empty($mapping['equipment_list'])) {
    echo "--- ОБРАБОТКА ФАЙЛОВ ДЛЯ EQUIPMENT_LIST ---\n";
    
    // Если указаны ID файлов
    if (!empty($fileIds)) {
        echo "Использование указанных файлов по ID: " . implode(', ', $fileIds) . "\n";
        $fileIdParam = count($fileIds) === 1 ? (int)$fileIds[0] : array_map('intval', $fileIds);
    }
    
    // Если указаны пути к файлам
    if (!empty($filePaths)) {
        echo "Загрузка файлов из указанных путей: " . implode(', ', $filePaths) . "\n";
        $validPaths = [];
        foreach ($filePaths as $filePath) {
            // Если путь относительный, пробуем найти файл относительно директории скрипта
            if (!file_exists($filePath) && !is_absolute_path($filePath)) {
                $absolutePath = __DIR__ . '/' . $filePath;
                if (file_exists($absolutePath)) {
                    $filePath = $absolutePath;
                }
            }
            
            if (file_exists($filePath)) {
                $validPaths[] = $filePath;
                echo "  ✓ Файл найден: {$filePath} (размер: " . filesize($filePath) . " байт)\n";
            } else {
                echo "  ✗ Файл не найден: {$filePath}\n";
            }
        }
        if (!empty($validPaths)) {
            $filePathParam = count($validPaths) === 1 ? $validPaths[0] : $validPaths;
        }
    }
    
    // Если не указаны ни ID, ни пути - используем тестовый файл
    if (empty($fileIdParam) && empty($filePathParam)) {
        $testFilePath = __DIR__ . '/test_equipment_list.txt';
        if (file_exists($testFilePath)) {
            echo "Использование тестового файла: {$testFilePath}\n";
            $filePathParam = $testFilePath;
        } else {
            echo "⚠ Тестовый файл не найден: {$testFilePath}\n";
            echo "  Создайте файл вручную или укажите файлы через параметры\n";
        }
    }
    
    if (empty($fileIdParam) && empty($filePathParam)) {
        echo "\n  ⚠ ВНИМАНИЕ: Поле equipment_list не будет заполнено (файлы не загружены)\n";
        echo "  Проект будет создан без файлов в поле 'Перечень оборудования'\n\n";
    } else {
        echo "\n  ✓ Параметры файлов подготовлены для createProjectCard\n";
        if ($fileIdParam) {
            echo "  File IDs: " . (is_array($fileIdParam) ? implode(', ', $fileIdParam) : $fileIdParam) . "\n";
        }
        if ($filePathParam) {
            echo "  File Paths: " . (is_array($filePathParam) ? implode(', ', $filePathParam) : $filePathParam) . "\n";
        }
        echo "\n";
    }
}

echo "Form fields для createProjectCard:\n";
echo json_encode($formFields, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
echo "\n";

echo "Отправка запроса через createProjectCard...\n";

$result = $bitrixAPI->createProjectCard($contactId, $formFields, $fileIdParam, $filePathParam, $localStorage);

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

