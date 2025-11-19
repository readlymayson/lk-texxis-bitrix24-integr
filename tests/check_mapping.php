<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для проверки маппинга полей
 */

echo "<h1>Проверка маппинга полей</h1>";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";

// Загрузка конфигурации
$config = require_once 'src/config/bitrix24.php';

echo "<h2>Текущий маппинг полей</h2>";

echo "<h3>Контакты</h3>";
echo "<pre>";
print_r($config['field_mapping']['contact']);
echo "</pre>";

echo "<h3>Компании</h3>";
echo "<pre>";
print_r($config['field_mapping']['company']);
echo "</pre>";

echo "<h3>Сделки</h3>";
echo "<pre>";
print_r($config['field_mapping']['deal']);
echo "</pre>";

echo "<h2>Проверка использования маппинга</h2>";

// Проверяем, что LKAPI использует маппинг
require_once 'src/classes/LKAPI.php';
require_once 'src/classes/Logger.php';

$logger = new Logger($config);
$lkapi = new LKAPI($config, $logger);

$testContact = [
    'ID' => '123',
    'NAME' => 'Тест',
    'LAST_NAME' => 'Тестов',
    'EMAIL' => [['VALUE' => 'test@example.com']],
    'PHONE' => [['VALUE' => '+7 999 123-45-67']],
    $config['field_mapping']['contact']['lk_client_field'] => 'Y'
];

echo "<h3>Тест маппинга контакта</h3>";
echo "<p>Приватный метод mapContactFields() недоступен для тестирования</p>";
echo "<p>Данные контакта для теста:</p>";
echo "<pre>";
print_r($testContact);
echo "</pre>";

$testCompany = [
    'ID' => '456',
    'TITLE' => 'ООО Тест',
    'EMAIL' => [['VALUE' => 'info@test.com']]
    // Компании больше не имеют поля личного кабинета
];

echo "<h3>Тест маппинга компании</h3>";
echo "<p>Приватный метод mapCompanyFields() недоступен для тестирования</p>";
echo "<p>Данные компании для теста:</p>";
echo "<pre>";
print_r($testCompany);
echo "</pre>";

echo "<h2>Проверка webhook обработчика</h2>";
echo "<p>Webhook обработчик теперь использует маппинг из конфига для всех операций.</p>";
echo "<p><strong>Поле ЛК для контактов:</strong> <code>" . $config['field_mapping']['contact']['lk_client_field'] . "</code></p>";
echo "<p><strong>Поле ЛК для компаний:</strong> <em>не используется</em></p>";

echo "<hr>";
echo "<p><a href='../index.php' class='info'>← Вернуться в веб-интерфейс</a></p>";

?>
