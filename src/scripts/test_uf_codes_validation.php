<?php
# -*- coding: utf-8 -*-

/**
 * Тест валидации UF кодов в конфигурации
 *
 * Проверяет:
 * - Корректность формата UF кодов
 * - Наличие всех необходимых полей
 * - Соответствие ожидаемому формату
 */

require_once __DIR__ . '/../classes/EnvLoader.php';
EnvLoader::load();

$config = require_once __DIR__ . '/../config/bitrix24.php';

echo "=== ТЕСТ ВАЛИДАЦИИ UF КОДОВ В КОНФИГУРАЦИИ ===\n\n";

$errors = [];
$warnings = [];

// Функция валидации UF кода
function validateUfCode($code, $fieldName, $entityType) {
    $errors = [];

    if (empty($code)) {
        $errors[] = "Пустой UF код для поля '{$fieldName}' в '{$entityType}'";
        return $errors;
    }

    // Стандартные поля Bitrix24 - не проверяем
    $standardFields = [
        'EMAIL', 'PHONE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'TYPE_ID', 'COMPANY_ID',
        'ASSIGNED_BY_ID', 'TITLE', 'CONTACT_ID', 'WEB', 'PERSONAL_MOBILE', 'WORK_POSITION',
        'PERSONAL_PHOTO', 'contactId', 'companyId', 'assignedById', 'stageId', '45'
    ];

    if (in_array($code, $standardFields)) {
        return $errors; // Стандартные поля - пропускаем валидацию
    }

    // Проверяем формат UF кодов
    if (preg_match('/^ufCrm\d+_/', $code)) {
        // Проверяем, что номер смарт-процесса корректный
        if (preg_match('/^ufCrm(\d+)/', $code, $matches)) {
            $smartProcessNumber = (int)$matches[1];
            $validNumbers = [7, 9, 11]; // Известные номера смарт-процессов

            if (!in_array($smartProcessNumber, $validNumbers)) {
                $errors[] = "Неизвестный номер смарт-процесса {$smartProcessNumber} в коде '{$code}' для поля '{$fieldName}' в '{$entityType}'";
            }
        }
    } elseif (preg_match('/^UF_/', $code)) {
        // Старый формат UF кодов - считаем допустимым
    } elseif (preg_match('/^ufUsr_/', $code)) {
        // Формат пользовательских полей - считаем допустимым
    } else {
        $errors[] = "Неверный формат кода '{$code}' для поля '{$fieldName}' в '{$entityType}'. Ожидается UF код или стандартное поле";
    }

    return $errors;
}

// Проверяем маппинг контактов
echo "1. Проверка маппинга контактов (contact)\n";
echo "========================================\n\n";

$contactMapping = $config['field_mapping']['contact'] ?? [];
foreach ($contactMapping as $fieldName => $ufCode) {
    if (is_array($ufCode)) {
        // Для массивов (например, значения списков)
        echo "ℹ️  Поле '{$fieldName}': массив значений\n";
        continue;
    }

    $fieldErrors = validateUfCode($ufCode, $fieldName, 'contact');
    if (!empty($fieldErrors)) {
        $errors = array_merge($errors, $fieldErrors);
        echo "❌ Поле '{$fieldName}': " . implode(', ', $fieldErrors) . "\n";
    } elseif (preg_match('/^(ufCrm|ufUsr|UF_)/', $ufCode)) {
        echo "✅ UF поле '{$fieldName}': {$ufCode}\n";
    } else {
        echo "ℹ️  Стандартное поле '{$fieldName}': {$ufCode}\n";
    }
}

// Проверяем маппинг компаний
echo "\n2. Проверка маппинга компаний (company)\n";
echo "=======================================\n\n";

$companyMapping = $config['field_mapping']['company'] ?? [];
foreach ($companyMapping as $fieldName => $ufCode) {
    if (is_array($ufCode)) {
        echo "ℹ️  Поле '{$fieldName}': массив значений\n";
        continue;
    }

    $fieldErrors = validateUfCode($ufCode, $fieldName, 'company');
    if (!empty($fieldErrors)) {
        $errors = array_merge($errors, $fieldErrors);
        echo "❌ Поле '{$fieldName}': " . implode(', ', $fieldErrors) . "\n";
    } elseif (preg_match('/^(ufCrm|ufUsr|UF_)/', $ufCode)) {
        echo "✅ UF поле '{$fieldName}': {$ufCode}\n";
    } else {
        echo "ℹ️  Стандартное поле '{$fieldName}': {$ufCode}\n";
    }
}

// Проверяем маппинг проектов
echo "\n3. Проверка маппинга проектов (smart_process)\n";
echo "=============================================\n\n";

$projectMapping = $config['field_mapping']['smart_process'] ?? [];
foreach ($projectMapping as $fieldName => $ufCode) {
    if (is_array($ufCode)) {
        continue;
    }

    $fieldErrors = validateUfCode($ufCode, $fieldName, 'smart_process');
    if (!empty($fieldErrors)) {
        $errors = array_merge($errors, $fieldErrors);
        echo "❌ Поле '{$fieldName}': " . implode(', ', $fieldErrors) . "\n";
    } elseif (preg_match('/^(ufCrm|ufUsr|UF_)/', $ufCode)) {
        echo "✅ UF поле '{$fieldName}': {$ufCode}\n";
    } else {
        echo "ℹ️  Стандартное поле '{$fieldName}': {$ufCode}\n";
    }
}

// Проверяем маппинг изменения данных
echo "\n4. Проверка маппинга изменения данных (smart_process_change_data)\n";
echo "================================================================\n\n";

$changeMapping = $config['field_mapping']['smart_process_change_data'] ?? [];
foreach ($changeMapping as $fieldName => $ufCode) {
    if (is_array($ufCode)) {
        continue;
    }

    $fieldErrors = validateUfCode($ufCode, $fieldName, 'smart_process_change_data');
    if (!empty($fieldErrors)) {
        $errors = array_merge($errors, $fieldErrors);
        echo "❌ Поле '{$fieldName}': " . implode(', ', $fieldErrors) . "\n";
    } elseif (preg_match('/^(ufCrm|ufUsr|UF_)/', $ufCode)) {
        echo "✅ UF поле '{$fieldName}': {$ufCode}\n";
    } else {
        echo "ℹ️  Стандартное поле '{$fieldName}': {$ufCode}\n";
    }
}

// Проверяем маппинг пользователей
echo "\n5. Проверка маппинга пользователей (user)\n";
echo "=========================================\n\n";

$userMapping = $config['field_mapping']['user'] ?? [];
foreach ($userMapping as $fieldName => $value) {
    if ($fieldName === 'messengers' && is_array($value)) {
        foreach ($value as $messenger => $ufCode) {
            $fieldErrors = validateUfCode($ufCode, "messengers.{$messenger}", 'user');
            if (!empty($fieldErrors)) {
                $errors = array_merge($errors, $fieldErrors);
                echo "❌ Поле 'messengers.{$messenger}': " . implode(', ', $fieldErrors) . "\n";
            } elseif (preg_match('/^(ufCrm|ufUsr|UF_)/', $ufCode)) {
                echo "✅ UF поле 'messengers.{$messenger}': {$ufCode}\n";
            } else {
                echo "ℹ️  Стандартное поле 'messengers.{$messenger}': {$ufCode}\n";
            }
        }
    } elseif (!is_array($value)) {
        if (preg_match('/^(ufCrm|ufUsr|UF_)/', $value)) {
            echo "✅ UF поле '{$fieldName}': {$value}\n";
        } else {
            echo "ℹ️  Стандартное поле '{$fieldName}': {$value}\n";
        }
    }
}

// Проверяем наличие обязательных полей
echo "\n6. Проверка обязательных полей\n";
echo "===============================\n\n";

$requiredFields = [
    'smart_process' => ['client_id', 'organization_name', 'object_name', 'status'],
    'smart_process_change_data' => ['contact_id', 'manager_id'],
    'smart_process_delete_data' => ['contact_id', 'manager_id'],
    'contact' => ['lk_client_field', 'email', 'name', 'type_id'],
    'company' => ['title', 'email'],
    'user' => ['name', 'email']
];

foreach ($requiredFields as $entityType => $fields) {
    if (!isset($config['field_mapping'][$entityType])) {
        $errors[] = "Отсутствует маппинг для '{$entityType}'";
        echo "❌ Отсутствует маппинг для '{$entityType}'\n";
        continue;
    }

    $mapping = $config['field_mapping'][$entityType];
    foreach ($fields as $field) {
        if (!isset($mapping[$field])) {
            $errors[] = "Отсутствует обязательное поле '{$field}' в маппинге '{$entityType}'";
            echo "❌ Отсутствует обязательное поле '{$field}' в '{$entityType}'\n";
        } else {
            echo "✅ Обязательное поле '{$field}' присутствует в '{$entityType}'\n";
        }
    }
}

// Проверяем уникальность UF кодов
echo "\n7. Проверка уникальности UF кодов\n";
echo "==================================\n\n";

$allUfCodes = [];
$duplicateCodes = [];

function collectUfCodes($mapping, &$allUfCodes, &$duplicateCodes) {
    foreach ($mapping as $fieldName => $value) {
        if (is_array($value)) {
            if ($fieldName === 'messengers') {
                // Специальная обработка для messengers
                foreach ($value as $messenger => $ufCode) {
                    if (!empty($ufCode)) {
                        if (in_array($ufCode, $allUfCodes)) {
                            $duplicateCodes[] = $ufCode;
                        } else {
                            $allUfCodes[] = $ufCode;
                        }
                    }
                }
            }
            // Для других массивов пропускаем
            continue;
        }

        if (!empty($value) && preg_match('/^(ufCrm|ufUsr)/', $value)) {
            if (in_array($value, $allUfCodes)) {
                $duplicateCodes[] = $value;
            } else {
                $allUfCodes[] = $value;
            }
        }
    }
}

foreach ($config['field_mapping'] as $entityType => $mapping) {
    collectUfCodes($mapping, $allUfCodes, $duplicateCodes);
}

if (!empty($duplicateCodes)) {
    $errors[] = "Найдены дублирующиеся UF коды: " . implode(', ', array_unique($duplicateCodes));
    echo "❌ Найдены дублирующиеся UF коды: " . implode(', ', array_unique($duplicateCodes)) . "\n";
} else {
    echo "✅ Все UF коды уникальны\n";
}

// ИТОГИ
echo "\n=== РЕЗУЛЬТАТЫ ВАЛИДАЦИИ ===\n\n";

if (empty($errors)) {
    echo "🎉 ВСЕ ПРОВЕРКИ ПРОШЛИ УСПЕШНО!\n";
    echo "Конфигурация UF кодов корректна.\n";
} else {
    echo "⚠️  НАЙДЕНЫ ОШИБКИ ВАЛИДАЦИИ!\n\n";
    echo "Количество ошибок: " . count($errors) . "\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
    echo "\nНеобходимо исправить ошибки перед использованием.\n";
}

echo "\n=== РЕКОМЕНДАЦИИ ===\n";
echo "- Все UF коды должны соответствовать формату ufCrm{N}_{timestamp}\n";
echo "- Для смарт-процессов используйте корректные номера (7, 9, 11)\n";
echo "- Проверяйте уникальность UF кодов\n";
echo "- При изменении структуры в Bitrix24 обновляйте конфигурацию\n";

?>
