<?php
# -*- coding: utf-8 -*-

/**
 * Скрипт для проверки сетевого доступа к веб-интерфейсу
 */

echo "<h1>Проверка сетевого доступа</h1>";
echo "<style>body { font-family: Arial, sans-serif; margin: 20px; } .success { color: green; } .error { color: red; } .info { color: blue; }</style>";

// Получение информации о сервере
echo "<h2>Информация о сервере</h2>";
$serverIP = trim(shell_exec("hostname -I | awk '{print $1}'") ?: 'Не определен');
$serverName = gethostname();
$port = 8000;

echo "<p><strong>Имя сервера:</strong> $serverName</p>";
echo "<p><strong>IP адрес:</strong> $serverIP</p>";
echo "<p><strong>Порт:</strong> $port</p>";

// Проверка доступности сервера
echo "<h2>Проверка доступности</h2>";

$localURL = "http://localhost:$port/";
$networkURL = "http://$serverIP:$port/";

echo "<h3>Локальный доступ:</h3>";
$localCheck = @file_get_contents($localURL);
if ($localCheck !== false && strpos($localCheck, 'Интеграция Битрикс24') !== false) {
    echo "<p class='success'>✓ Сервер доступен локально: <a href='$localURL' target='_blank'>$localURL</a></p>";
} else {
    echo "<p class='error'>✗ Сервер НЕ доступен локально</p>";
}

echo "<h3>Сетевой доступ:</h3>";
$networkCheck = @file_get_contents($networkURL);
if ($networkCheck !== false && strpos($networkCheck, 'Интеграция Битрикс24') !== false) {
    echo "<p class='success'>✓ Сервер доступен из сети: <a href='$networkURL' target='_blank'>$networkURL</a></p>";
} else {
    echo "<p class='error'>✗ Сервер НЕ доступен из сети</p>";
    echo "<p class='info'>Возможные причины:</p>";
    echo "<ul>";
    echo "<li>Сервер не запущен или запущен только на localhost</li>";
    echo "<li>Брандмауэр блокирует входящие соединения</li>";
    echo "<li>Неправильный IP адрес</li>";
    echo "</ul>";
}

// Инструкции по доступу
echo "<h2>Инструкции по доступу</h2>";
echo "<h3>С компьютера в той же сети:</h3>";
echo "<ol>";
echo "<li>Откройте браузер</li>";
echo "<li>Введите адрес: <code>http://$serverIP:$port/</code></li>";
echo "<li>Нажмите Enter</li>";
echo "</ol>";

echo "<h3>Если доступ не работает:</h3>";
echo "<ol>";
echo "<li>Убедитесь, что сервер запущен: <code>./start_server.sh</code></li>";
echo "<li>Проверьте IP адрес: <code>hostname -I</code></li>";
echo "<li>Отключите брандмауэр временно для тестирования</li>";
echo "<li>Попробуйте с другого устройства в сети</li>";
echo "</ol>";

// Быстрые ссылки
echo "<h2>Быстрые ссылки</h2>";
echo "<p><a href='$localURL' class='success' target='_blank'>🌐 Открыть локально</a></p>";
echo "<p><a href='$networkURL' class='success' target='_blank'>🌐 Открыть из сети</a></p>";
echo "<p><a href='check_web.php' class='info'>🔍 Проверить систему</a></p>";

?>
