<?php
echo "PHP работает! Время: " . date('Y-m-d H:i:s') . "\n";
echo "REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'not set') . "\n";
echo "POST данные: " . json_encode($_POST) . "\n";
