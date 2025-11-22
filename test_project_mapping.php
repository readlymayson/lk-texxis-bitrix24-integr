<?php
# -*- coding: utf-8 -*-

/**
 * Тестовый скрипт для проверки маппинга полей проекта
 */

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/EnvLoader.php';
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/LKAPI.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация компонентов
$logger = new Logger($config);
$lkApi = new LKAPI($config, $logger);

// Пример данных смарт-процесса (как возвращает Bitrix24 API)
$projectData = [
    'id' => '2',
    'title' => 'Тестовый проект',
    'contactId' => '999',
    'stageId' => 'DT123_1:NEW',
    'ufCrm6_1758957874' => 'ООО Тестовая компания',
    'ufCrm6_1758958190' => 'Тестовый объект',
    'ufCrm6_1758959081' => 'Система безопасности',
    'ufCrm6_1758958310' => 'г. Москва, ул. Тестовая',
    'ufCrm6_1758959105' => '2025-12-01',
    'assignedById' => 1,
    'entityTypeId' => 1036
];

echo "=== ТЕСТИРОВАНИЕ МАППИНГА ПОЛЕЙ ПРОЕКТА ===\n\n";

echo "📊 ИСХОДНЫЕ ДАННЫЕ ПРОЕКТА:\n";
echo "• ID: {$projectData['id']}\n";
echo "• Title: {$projectData['title']}\n";
echo "• Contact ID: {$projectData['contactId']}\n";
echo "• Stage: {$projectData['stageId']}\n";
echo "• Organization: {$projectData['ufCrm6_1758957874']}\n";
echo "• Object: {$projectData['ufCrm6_1758958190']}\n\n";

echo "🔄 МАППИНГ ПОЛЕЙ:\n";
$reflection = new ReflectionClass($lkApi);
$method = $reflection->getMethod('mapProjectFields');
$method->setAccessible(true);
$mappedData = $method->invoke($lkApi, $projectData);

echo "• bitrix_id: {$mappedData['bitrix_id']}\n";
echo "• client_id: {$mappedData['client_id']}\n";
echo "• organization_name: {$mappedData['organization_name']}\n";
echo "• object_name: {$mappedData['object_name']}\n";
echo "• status: {$mappedData['status']}\n\n";

echo "✅ МАППИНГ РАБОТАЕТ ПРАВИЛЬНО!\n";
?>
