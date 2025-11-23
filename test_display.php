<?php
require_once 'src/classes/EnvLoader.php';
require_once 'src/classes/Logger.php';
require_once 'src/classes/LocalStorage.php';

$config = require_once 'src/config/bitrix24.php';
$logger = new Logger($config);
$localStorage = new LocalStorage($logger);

// Симулируем данные как в index.php
$bitrixData = [
    'deals' => [],
    'projects' => [],
    'managers' => [],
    'contacts' => [],
    'companies' => []
];

$lastContact = $localStorage->getLastUpdatedContact();
if ($lastContact) {
    // Получаем все сделки для отображения
    $allDeals = $localStorage->getAllDeals();
    $contactDeals = array_filter($allDeals, function($deal) use ($lastContact) {
        return $deal['contact_id'] == $lastContact['bitrix_id'];
    });
    $bitrixData['deals'] = !empty($contactDeals) ? $contactDeals : $allDeals;
    
    $bitrixData['projects'] = $localStorage->getAllProjects();
    $bitrixData['managers'] = $localStorage->getAllManagers();
    $bitrixData['contacts'] = $localStorage->getAllContacts();
    $bitrixData['companies'] = $localStorage->getAllCompanies();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Display</title>
</head>
<body>
    <h1>Test Entity Display</h1>
    
    <?php if (!empty($bitrixData['deals'])): ?>
    <h2>Сделки (<?php echo count($bitrixData['deals']); ?>)</h2>
    <ul>
        <?php foreach ($bitrixData['deals'] as $deal): ?>
        <li><?php echo htmlspecialchars($deal['title'] ?? $deal['id']); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($bitrixData['managers'])): ?>
    <h2>Менеджеры (<?php echo count($bitrixData['managers']); ?>)</h2>
    <ul>
        <?php foreach ($bitrixData['managers'] as $manager): ?>
        <li><?php echo htmlspecialchars(($manager['name'] ?? '') . ' ' . ($manager['last_name'] ?? '')); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <?php if (!empty($bitrixData['projects'])): ?>
    <h2>Проекты (<?php echo count($bitrixData['projects']); ?>)</h2>
    <ul>
        <?php foreach ($bitrixData['projects'] as $project): ?>
        <li><?php echo htmlspecialchars($project['organization_name'] ?? $project['bitrix_id']); ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2>Debug Info</h2>
    <pre><?php echo json_encode($bitrixData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></pre>
</body>
</html>
