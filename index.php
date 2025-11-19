<?php
# -*- coding: utf-8 -*-

/**
 * Главная страница - Личный кабинет с данными из Bitrix24
 */

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация логгера и API
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);

// Демо email для отображения данных
$demoEmail = 'frolov.ffff@yandex.ru';

// Получение данных пользователя из Bitrix24
$userData = null;
$bitrixData = [
    'contact' => null,
    'company' => null,
    'deals' => [],
    'projects' => []
];

try {
    // Получаем контакт по email
    $contacts = $bitrixAPI->getEntityList('contact', [
        'EMAIL' => $demoEmail
    ], ['ID', 'NAME', 'LAST_NAME', 'EMAIL', 'PHONE', 'COMPANY_ID', 'DATE_CREATE']);

    if ($contacts && isset($contacts['result']) && !empty($contacts['result'])) {
        $bitrixData['contact'] = $contacts['result'][0];

        // Формируем данные пользователя
        $userData = [
            'id' => 'BX-' . $bitrixData['contact']['ID'],
            'name' => $bitrixData['contact']['NAME'] . ' ' . ($bitrixData['contact']['LAST_NAME'] ?? ''),
            'email' => $demoEmail,
            'phone' => extractPhoneFromBitrix($bitrixData['contact']['PHONE'] ?? []),
            'type' => 'individual',
            'company' => $bitrixData['contact']['COMPANY_ID'] ?? null,
            'role' => 'client',
            'bitrix_id' => $bitrixData['contact']['ID'],
            'registered_at' => $bitrixData['contact']['DATE_CREATE'] ?? date('Y-m-d'),
            'last_login' => date('Y-m-d H:i:s'),
            'status' => 'active'
        ];

        // Получаем сделки контакта
        $deals = $bitrixAPI->getEntityList('deal', [
            'CONTACT_ID' => $bitrixData['contact']['ID']
        ], ['ID', 'TITLE', 'STAGE_ID', 'OPPORTUNITY', 'CURRENCY_ID', 'DATE_CREATE', 'DATE_MODIFY']);

        if ($deals && isset($deals['result'])) {
            $bitrixData['deals'] = $deals['result'];
        }

        // Получаем компанию, если указана
        if (!empty($bitrixData['contact']['COMPANY_ID'])) {
            $companyData = $bitrixAPI->getEntityData('company', $bitrixData['contact']['COMPANY_ID']);
            if ($companyData && isset($companyData['result'])) {
                $bitrixData['company'] = $companyData['result'];
            }
        }
    } else {
        // Контакт не найден в Bitrix24
        $userData = [
            'id' => 'NOT_FOUND',
            'name' => 'Пользователь не найден',
            'email' => $demoEmail,
            'phone' => '',
            'type' => 'individual',
            'company' => null,
            'role' => 'client',
            'bitrix_id' => null,
            'registered_at' => date('Y-m-d'),
            'last_login' => date('Y-m-d H:i:s'),
            'status' => 'not_found'
        ];
        $bitrixData = [
            'contact' => null,
            'company' => null,
            'deals' => [],
            'projects' => []
        ];
    }

} catch (Exception $e) {
    $logger->error('Error loading data from Bitrix24', [
        'email' => $demoEmail,
        'error' => $e->getMessage()
    ]);

    // При ошибке API показываем сообщение об ошибке
    $userData = [
        'id' => 'API_ERROR',
        'name' => 'Ошибка подключения',
        'email' => $demoEmail,
        'phone' => '',
        'type' => 'individual',
        'company' => null,
        'role' => 'client',
        'bitrix_id' => null,
        'registered_at' => date('Y-m-d'),
        'last_login' => date('Y-m-d H:i:s'),
        'status' => 'api_error'
    ];
    $bitrixData = [
        'contact' => null,
        'company' => null,
        'deals' => [],
        'projects' => []
    ];
}

// Проверка наличия данных из Bitrix24
$hasBitrixData = $bitrixData['contact'] !== null;

/**
 * Извлечение телефона из данных Bitrix24
 */
function extractPhoneFromBitrix($phones) {
    if (is_array($phones) && !empty($phones)) {
        foreach ($phones as $phone) {
            if (isset($phone['VALUE'])) {
                return $phone['VALUE'];
            }
        }
    }
    return '';
}

/**
 * Форматирование даты из Bitrix24 формата
 */
function formatBitrixDate($dateString) {
    if (!$dateString) return 'Не указана';

    try {
        $date = new DateTime($dateString);
        return $date->format('d.m.Y H:i');
    } catch (Exception $e) {
        return $dateString;
    }
}

/**
 * Получение цвета для стадии сделки
 */
function getDealStageColor($stageId) {
    $colors = [
        'NEW' => 'primary',
        'PREPARATION' => 'info',
        'PREPAYMENT_INVOICE' => 'warning',
        'EXECUTING' => 'secondary',
        'FINAL_INVOICE' => 'warning',
        'WON' => 'success',
        'LOSE' => 'danger'
    ];
    return $colors[$stageId] ?? 'secondary';
}

/**
 * Получение текста для стадии сделки
 */
function getDealStageText($stageId) {
    $texts = [
        'NEW' => 'Новая',
        'PREPARATION' => 'Подготовка',
        'PREPAYMENT_INVOICE' => 'Предоплата',
        'EXECUTING' => 'Выполнение',
        'FINAL_INVOICE' => 'Финальный счет',
        'WON' => 'Выиграна',
        'LOSE' => 'Проиграна'
    ];
    return $texts[$stageId] ?? $stageId;
}

/**
 * Получение цвета для стадии проекта
 */
function getProjectStageColor($stageId) {
    $colors = [
        'DT123_1:NEW' => 'primary',
        'DT123_1:PREPARATION' => 'info',
        'DT123_1:EXECUTING' => 'warning',
        'DT123_1:COMPLETED' => 'success'
    ];
    return $colors[$stageId] ?? 'secondary';
}

/**
 * Получение текста для стадии проекта
 */
function getProjectStageText($stageId) {
    $texts = [
        'DT123_1:NEW' => 'Новый',
        'DT123_1:PREPARATION' => 'Подготовка',
        'DT123_1:EXECUTING' => 'Выполнение',
        'DT123_1:COMPLETED' => 'Завершен'
    ];
    return $texts[$stageId] ?? $stageId;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет - <?php echo htmlspecialchars($userData['name'] ?? 'Пользователь'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .profile-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .data-table th { background-color: #f8f9fa; }
        .bitrix-badge { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.75em; }
        .json-viewer { background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; padding: 15px; font-family: 'Courier New', monospace; font-size: 0.85em; max-height: 400px; overflow-y: auto; }
        .navbar-brand { font-weight: bold; }
    </style>
</head>
<body>
    <!-- Навигационная панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="#">
                <i class="fas fa-user-circle me-2"></i>Личный кабинет
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text">
                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($demoEmail); ?>
                </span>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <!-- Заголовок -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="profile-header card-header">
                        <h2 class="mb-0">
                            <i class="fas fa-id-card me-2"></i>
                            Профиль пользователя: <?php echo htmlspecialchars($userData['name'] ?? 'Неизвестный пользователь'); ?>
                        </h2>
                        <small class="text-light opacity-75">Данные из Bitrix24</small>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Личный кабинет</strong><br>
                            Отображаются данные пользователя <strong><?php echo htmlspecialchars($demoEmail); ?></strong> из Bitrix24
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основная информация -->
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-user me-2"></i>Основная информация</h4>
            </div>
            <div class="card-body">
                <div class="row">
                            <div class="col-md-6">
                                <h5>Личный кабинет</h5>
                                <table class="table table-sm data-table">
                                    <tr><th>ID:</th><td><?php echo htmlspecialchars($userData['id']); ?> <span class="bitrix-badge">LK</span></td></tr>
                                    <tr><th>Имя:</th><td><?php echo htmlspecialchars($userData['name']); ?></td></tr>
                                    <tr><th>Email:</th><td><?php echo htmlspecialchars($userData['email']); ?></td></tr>
                                    <tr><th>Телефон:</th><td><?php echo htmlspecialchars($userData['phone'] ?: 'Не указан'); ?></td></tr>
                                    <tr><th>Тип:</th><td><?php echo htmlspecialchars($userData['type'] === 'individual' ? 'Физическое лицо' : 'Юридическое лицо'); ?></td></tr>
                                    <tr><th>Статус:</th><td>
                                        <?php if ($userData['status'] === 'not_found'): ?>
                                            <span class="badge bg-danger">Пользователь не найден</span>
                                        <?php elseif ($userData['status'] === 'api_error'): ?>
                                            <span class="badge bg-warning">Ошибка подключения</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($userData['status']); ?></span>
                                        <?php endif; ?>
                                    </td></tr>
                                    <tr><th>Зарегистрирован:</th><td><?php echo date('d.m.Y', strtotime($userData['registered_at'])); ?></td></tr>
                                </table>
                            </div>
                    <div class="col-md-6">
                        <?php if ($hasBitrixData): ?>
                        <h5>Bitrix24 - Контакт</h5>
                        <table class="table table-sm data-table">
                            <tr><th>ID:</th><td><?php echo htmlspecialchars($bitrixData['contact']['ID']); ?> <span class="bitrix-badge">BX24</span></td></tr>
                            <tr><th>Имя:</th><td><?php echo htmlspecialchars($bitrixData['contact']['NAME']); ?></td></tr>
                            <tr><th>Фамилия:</th><td><?php echo htmlspecialchars($bitrixData['contact']['LAST_NAME'] ?? ''); ?></td></tr>
                            <tr><th>Email:</th><td><?php echo htmlspecialchars($bitrixData['contact']['EMAIL'] ? $bitrixData['contact']['EMAIL'][0]['VALUE'] : $userData['email']); ?></td></tr>
                            <tr><th>Телефон:</th><td><?php echo htmlspecialchars($bitrixData['contact']['PHONE'] ? $bitrixData['contact']['PHONE'][0]['VALUE'] : 'Не указан'); ?></td></tr>
                            <tr><th>Должность:</th><td><?php echo htmlspecialchars($bitrixData['contact']['POST'] ?? 'Не указана'); ?></td></tr>
                            <tr><th>Компания:</th><td><?php echo htmlspecialchars($bitrixData['contact']['COMPANY_ID'] ? 'ID: ' . $bitrixData['contact']['COMPANY_ID'] : 'Не указана'); ?></td></tr>
                            <tr><th>Создан:</th><td><?php echo formatBitrixDate($bitrixData['contact']['DATE_CREATE'] ?? $userData['registered_at']); ?></td></tr>
                            <tr><th>Изменен:</th><td><?php echo formatBitrixDate($bitrixData['contact']['DATE_MODIFY'] ?? date('Y-m-d H:i:s')); ?></td></tr>
                        </table>
                        <?php else: ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Данные из Bitrix24 недоступны</strong><br>
                            <small>Контакт с email <?php echo htmlspecialchars($userData['email']); ?> не найден в Bitrix24 или API временно недоступен.</small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($hasBitrixData): ?>
        <!-- Информация о компании -->
        <?php if ($bitrixData['company']): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-building me-2"></i>Информация о компании (Bitrix24)</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm data-table">
                            <tr><th>ID:</th><td><?php echo htmlspecialchars($bitrixData['company']['ID']); ?> <span class="bitrix-badge">BX24</span></td></tr>
                            <tr><th>Название:</th><td><?php echo htmlspecialchars($bitrixData['company']['TITLE']); ?></td></tr>
                            <tr><th>Тип:</th><td><?php echo htmlspecialchars($bitrixData['company']['COMPANY_TYPE'] ?? 'Не указан'); ?></td></tr>
                            <tr><th>Email:</th><td><?php echo htmlspecialchars($bitrixData['company']['EMAIL'] ? $bitrixData['company']['EMAIL'][0]['VALUE'] : 'Не указан'); ?></td></tr>
                            <tr><th>Телефон:</th><td><?php echo htmlspecialchars($bitrixData['company']['PHONE'] ? $bitrixData['company']['PHONE'][0]['VALUE'] : 'Не указан'); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm data-table">
                            <tr><th>Отрасль:</th><td><?php echo htmlspecialchars($bitrixData['company']['INDUSTRY'] ?? 'Не указана'); ?></td></tr>
                            <tr><th>Сотрудники:</th><td><?php echo htmlspecialchars($bitrixData['company']['EMPLOYEES'] ?? 'Не указано'); ?></td></tr>
                            <tr><th>Выручка:</th><td><?php echo $bitrixData['company']['REVENUE'] ? number_format($bitrixData['company']['REVENUE'], 0, ',', ' ') . ' ₽' : 'Не указана'; ?></td></tr>
                            <tr><th>Адрес:</th><td><?php echo htmlspecialchars($bitrixData['company']['ADDRESS'] ?? 'Не указан'); ?></td></tr>
                            <tr><th>Создан:</th><td><?php echo formatBitrixDate($bitrixData['company']['DATE_CREATE'] ?? 'Неизвестно'); ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Сделки из Bitrix24 -->
        <?php if (!empty($bitrixData['deals'])): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-handshake me-2"></i>Сделки (Bitrix24)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Стадия</th>
                                <th>Сумма</th>
                                <th>Вероятность</th>
                                <th>Создан</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bitrixData['deals'] as $deal): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($deal['ID']); ?></td>
                                <td><?php echo htmlspecialchars($deal['TITLE']); ?></td>
                                <td><span class="badge bg-<?php echo getDealStageColor($deal['STAGE_ID']); ?>"><?php echo getDealStageText($deal['STAGE_ID']); ?></span></td>
                                <td><?php echo number_format($deal['OPPORTUNITY'], 0, ',', ' '); ?> <?php echo ($deal['CURRENCY_ID'] === 'RUB' ? '₽' : '$'); ?></td>
                                <td><?php echo $deal['PROBABILITY']; ?>%</td>
                                <td><?php echo formatBitrixDate($deal['DATE_CREATE']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Сообщение о недоступности данных -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h4 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Данные недоступны</h4>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <h5><i class="fas fa-info-circle me-2"></i>Причина отсутствия данных</h5>
                    <ul class="mb-0">
                        <?php if ($userData['status'] === 'not_found'): ?>
                            <li>Контакт с email <strong><?php echo htmlspecialchars($userData['email']); ?></strong> не найден в Bitrix24</li>
                            <li>Проверьте правильность email адреса</li>
                        <?php elseif ($userData['status'] === 'api_error'): ?>
                            <li>Ошибка подключения к API Bitrix24</li>
                            <li>Проверьте настройки интеграции</li>
                            <li>Убедитесь в доступности сервера Bitrix24</li>
                        <?php else: ?>
                            <li>API Bitrix24 временно недоступен</li>
                            <li>Отсутствует подключение к интернету</li>
                            <li>Некорректные настройки интеграции</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6>Что делать?</h6>
                        <ol>
                            <?php if ($userData['status'] === 'not_found'): ?>
                                <li>Убедитесь, что контакт с email <?php echo htmlspecialchars($userData['email']); ?> существует в Bitrix24</li>
                                <li>Проверьте правильность email адреса</li>
                                <li>Свяжитесь с администратором CRM</li>
                            <?php elseif ($userData['status'] === 'api_error'): ?>
                                <li>Проверьте настройки webhook URL в конфигурации</li>
                                <li>Убедитесь в корректности API ключей</li>
                                <li>Свяжитесь с администратором системы</li>
                            <?php else: ?>
                                <li>Попробуйте обновить страницу через некоторое время</li>
                                <li>Свяжитесь с администратором системы</li>
                            <?php endif; ?>
                        </ol>
                    </div>
                    <div class="col-md-6">
                        <h6>Информация для отладки:</h6>
                        <small class="text-muted">
                            Email: <?php echo htmlspecialchars($userData['email']); ?><br>
                            Статус: <?php
                                if ($userData['status'] === 'not_found') echo 'Пользователь не найден';
                                elseif ($userData['status'] === 'api_error') echo 'Ошибка API';
                                else echo htmlspecialchars($userData['status']);
                            ?><br>
                            Время проверки: <?php echo date('d.m.Y H:i:s'); ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- RAW JSON данные -->
        <div class="card">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-code me-2"></i>RAW данные из Bitrix24</h4>
            </div>
            <div class="card-body">
                <?php if ($hasBitrixData): ?>
                    <div class="alert alert-info mb-3">
                        <small>Показаны реальные данные из Bitrix24 API для пользователя <?php echo htmlspecialchars($demoEmail); ?>.</small>
                    </div>
                    <div class="json-viewer"><?php echo json_encode($bitrixData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></div>
                <?php else: ?>
                    <div class="alert alert-warning mb-3">
                        <small>Данные из Bitrix24 недоступны. <?php
                            if ($userData['status'] === 'not_found') {
                                echo 'Контакт с указанным email не найден в системе.';
                            } elseif ($userData['status'] === 'api_error') {
                                echo 'Ошибка подключения к API Bitrix24.';
                            } else {
                                echo 'Проверьте настройки интеграции.';
                            }
                        ?></small>
                    </div>
                    <div class="json-viewer"><?php echo json_encode($bitrixData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
