<?php
# -*- coding: utf-8 -*-

/**
 * Главная страница - Личный кабинет с данными из Bitrix24
 */

// Подключение необходимых классов
require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';

// Загрузка конфигурации
$config = require_once __DIR__ . '/src/config/bitrix24.php';

// Инициализация логгера, API и локального хранилища
$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger);

// Данные пользователя (автоматически загружаем последний обновленный)
$userData = null;
$bitrixData = [
    'contact' => null,
    'company' => null,
    'deals' => [],
    'projects' => [],
    'managers' => [],
    'contacts' => [],
    'companies' => []
];
$availableContacts = [];

// Автоматически загружаем последний обновленный контакт
try {
    // Получаем все контакты отсортированные по времени обновления
    $availableContacts = $localStorage->getContactsSortedByUpdate(10); // Последние 10 контактов

    // Показываем последний обновленный контакт
    $lastContact = $localStorage->getLastUpdatedContact();

    if ($lastContact) {
        $userData = $lastContact;
        $bitrixData['contact'] = [
            'ID' => $lastContact['bitrix_id'],
            'NAME' => $lastContact['name'],
            'LAST_NAME' => $lastContact['last_name'],
            'EMAIL' => is_array($lastContact['email']) ? $lastContact['email'] : [['VALUE' => $lastContact['email']]],
            'PHONE' => is_array($lastContact['phone']) ? $lastContact['phone'] : [['VALUE' => $lastContact['phone']]],
            'COMPANY_ID' => $lastContact['company']
        ];

        // Получаем связанные данные
        if (!empty($lastContact['company'])) {
            $bitrixData['company'] = $localStorage->getCompany($lastContact['company']);
        }

        // Получаем все сделки для отображения
        $allDeals = $localStorage->getAllDeals();
        $bitrixData['deals'] = array_filter($allDeals, function($deal) use ($lastContact) {
            return $deal['contact_id'] == $lastContact['bitrix_id'];
        });

        // Получаем все проекты, менеджеров, контакты и компании для отображения
        $bitrixData['projects'] = $localStorage->getAllProjects();
        $bitrixData['managers'] = $localStorage->getAllManagers();
        $bitrixData['contacts'] = $localStorage->getAllContacts();
        $bitrixData['companies'] = $localStorage->getAllCompanies();
    }
} catch (Exception $e) {
    $logger->error('Error loading last contact data', ['error' => $e->getMessage()]);
}

// Обработка выбора контакта из списка
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_contact'])) {
    $selectedContactId = $_POST['select_contact'] ?? '';

    if (!empty($selectedContactId) && isset($availableContacts[$selectedContactId])) {
        $selectedContact = $availableContacts[$selectedContactId];

        $userData = $selectedContact;
        $bitrixData['contact'] = [
            'ID' => $selectedContact['bitrix_id'],
            'NAME' => $selectedContact['name'],
            'LAST_NAME' => $selectedContact['last_name'],
            'EMAIL' => is_array($selectedContact['email']) ? $selectedContact['email'] : [['VALUE' => $selectedContact['email']]],
            'PHONE' => is_array($selectedContact['phone']) ? $selectedContact['phone'] : [['VALUE' => $selectedContact['phone']]],
            'COMPANY_ID' => $selectedContact['company']
        ];

        // Получаем связанные данные
        if (!empty($selectedContact['company'])) {
            $bitrixData['company'] = $localStorage->getCompany($selectedContact['company']);
        }

        // Получаем все сделки для отображения
        $allDeals = $localStorage->getAllDeals();
        $bitrixData['deals'] = array_filter($allDeals, function($deal) use ($selectedContact) {
            return $deal['contact_id'] == $selectedContact['bitrix_id'];
        });

        // Получаем все проекты, менеджеров, контакты и компании для отображения
        $bitrixData['projects'] = $localStorage->getAllProjects();
        $bitrixData['managers'] = $localStorage->getAllManagers();
        $bitrixData['contacts'] = $localStorage->getAllContacts();
        $bitrixData['companies'] = $localStorage->getAllCompanies();
    }
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
 * Получение цвета для статуса проекта
 */
function getProjectStatusColor($status) {
    $colors = [
        'DT123_1:NEW' => 'primary',
        'DT123_1:PREPARATION' => 'info',
        'DT123_1:EXECUTING' => 'warning',
        'DT123_1:COMPLETED' => 'success',
        'DT123_1:CANCELLED' => 'danger'
    ];
    return $colors[$status] ?? 'secondary';
}

/**
 * Получение текста для статуса проекта
 */
function getProjectStatusText($status) {
    $texts = [
        'DT123_1:NEW' => 'Новый',
        'DT123_1:PREPARATION' => 'Подготовка',
        'DT123_1:EXECUTING' => 'Выполнение',
        'DT123_1:COMPLETED' => 'Завершен',
        'DT123_1:CANCELLED' => 'Отменен'
    ];
    return $texts[$status] ?? $status;
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
                    <i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars(is_array($userData['email'] ?? null) ? ($userData['email'][0]['VALUE'] ?? '') : ($userData['email'] ?? '')); ?>
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
                            Профиль пользователя: <?php echo htmlspecialchars(($userData['name'] ?? '') . ' ' . ($userData['last_name'] ?? '') ?: 'Не выбран пользователь'); ?>
                        </h2>
                        <small class="text-light opacity-75">Данные из Bitrix24</small>
                    </div>
                    <div class="card-body">
                        <!-- Селектор контактов -->
                        <?php if (!empty($availableContacts)): ?>
                            <form method="POST" class="mb-3">
                                <div class="input-group">
                                    <select name="select_contact" class="form-select" onchange="this.form.submit()">
                                        <option value="">-- Выберите контакт --</option>
                                        <?php foreach ($availableContacts as $contactId => $contact): ?>
                                            <option value="<?php echo htmlspecialchars($contactId); ?>"
                                                    <?php echo ($userData && $userData['bitrix_id'] == $contact['bitrix_id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($contact['name'] . ' ' . ($contact['last_name'] ?? '')); ?>
                                                (<?php echo htmlspecialchars(is_array($contact['email']) ? ($contact['email'][0]['VALUE'] ?? 'без email') : ($contact['email'] ?: 'без email')); ?>)
                                                - обновлен <?php echo date('d.m.Y H:i', strtotime($contact['updated_at'] ?? $contact['created_at'])); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-eye me-1"></i>Показать
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <!-- Информация о системе привязки -->
                        <div class="alert alert-info mt-3">
                            <h5><i class="fas fa-info-circle me-2"></i>Система привязки к личным кабинетам</h5>
                            <div class="row">
                                <div class="col-md-4">
                                    <h6><i class="fas fa-user me-1"></i>Контакты</h6>
                                    <small class="text-muted">
                                        Сохраняются локально по bitrix_id<br>
                                        Создание только при наличии поля UF_CRM_1763468430<br>
                                        Автоматически подтягиваются связанные компании и проекты
                                    </small>
                                </div>
                                <div class="col-md-4">
                                    <h6><i class="fas fa-building me-1"></i>Компании</h6>
                                    <small class="text-muted">
                                        Сохраняются локально по bitrix_id<br>
                                        Привязываются к контактам через CONTACT_ID<br>
                                        Обрабатываются только если CONTACT_ID указан<br>
                                        и соответствует существующему контакту
                                    </small>
                                </div>
                                <div class="col-md-4">
                                    <h6><i class="fas fa-project-diagram me-1"></i>Проекты</h6>
                                    <small class="text-muted">
                                        Сохраняются локально по bitrix_id<br>
                                        Привязываются к клиентам через contactId<br>
                                        Обрабатываются только при наличии контакта
                                    </small>
                                </div>
                            </div>
                            <hr>
                            <div class="row">
                                <div class="col-md-6">
                                    <h6><i class="fas fa-database me-1"></i>Текущая статистика</h6>
                                    <ul class="list-unstyled mb-0 small">
                                        <li><strong>Контактов:</strong> <?php echo count($bitrixData['contacts'] ?? []); ?> (локально)</li>
                                        <li><strong>Компаний:</strong> <?php echo count($bitrixData['companies'] ?? []); ?> (связаны с контактами)</li>
                                        <li><strong>Проектов:</strong> <?php echo count($bitrixData['projects'] ?? []); ?> (по клиентам)</li>
                                        <li><strong>Менеджеров:</strong> <?php echo count($bitrixData['managers'] ?? []); ?> (справочно)</li>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6><i class="fas fa-cogs me-1"></i>Настройки интеграции</h6>
                                    <ul class="list-unstyled mb-0 small">
                                        <li><strong>Smart Process ID:</strong> <?php echo htmlspecialchars($config['bitrix24']['smart_process_id'] ?? 'не указан'); ?></li>
                                        <li><strong>Webhook URL:</strong> <?php echo htmlspecialchars($config['bitrix24']['webhook_url'] ?? 'не настроен'); ?></li>
                                        <li><strong>Логирование:</strong> <?php echo ($config['logging']['enabled'] ?? true) ? 'включено' : 'отключено'; ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <!-- Информация о текущих данных -->
                        <?php if ($hasBitrixData && $userData): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-user-check me-2"></i>
                                <strong>Личный кабинет загружен</strong><br>
                                Показан последний обновленный контакт: <strong><?php echo htmlspecialchars($userData['name'] . ' ' . ($userData['last_name'] ?? '')); ?></strong>
                                <br><small class="text-muted">Обновлен: <?php echo date('d.m.Y H:i:s', strtotime($userData['updated_at'] ?? $userData['created_at'])); ?></small>
                            </div>
                        <?php elseif (!empty($availableContacts)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-users me-2"></i>
                                <strong>Выберите контакт</strong><br>
                                Доступно <?php echo count($availableContacts); ?> контактов. Выберите из списка выше или дождитесь webhook от Битрикс24.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Нет данных</strong><br>
                                Личные кабинеты еще не созданы. Данные появятся после получения webhook от Битрикс24.
                            </div>
                        <?php endif; ?>

                        <!-- Статистика -->
                        <?php if (!empty($availableContacts)): ?>
                            <div class="row mt-3">
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <div class="h4 text-primary"><?php echo count($availableContacts); ?></div>
                                        <small class="text-muted">Всего контактов</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <div class="h4 text-success"><?php echo count(array_filter($availableContacts, fn($c) => $c['status'] === 'active')); ?></div>
                                        <small class="text-muted">Активных</small>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="text-center">
                                        <div class="h4 text-info"><?php echo count($bitrixData['deals']); ?></div>
                                        <small class="text-muted">Сделок показано</small>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основная информация -->
        <?php if ($userData): ?>
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
                                    <tr><th>Email:</th><td><?php echo htmlspecialchars(is_array($userData['email']) ? ($userData['email'][0]['VALUE'] ?? 'Не указан') : ($userData['email'] ?: 'Не указан')); ?></td></tr>
                                    <tr><th>Телефон:</th><td><?php echo htmlspecialchars(is_array($userData['phone']) ? ($userData['phone'][0]['VALUE'] ?? 'Не указан') : ($userData['phone'] ?: 'Не указан')); ?></td></tr>
                                    <tr><th>Тип:</th><td><?php echo htmlspecialchars(($userData['type'] ?? '') === 'individual' ? 'Физическое лицо' : 'Юридическое лицо'); ?></td></tr>
                                    <tr><th>Статус:</th><td>
                                        <?php if ($userData['status'] === 'not_found'): ?>
                                            <span class="badge bg-danger">Пользователь не найден</span>
                                        <?php elseif ($userData['status'] === 'api_error'): ?>
                                            <span class="badge bg-warning">Ошибка подключения</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($userData['status']); ?></span>
                                        <?php endif; ?>
                                    </td></tr>
                                    <tr><th>Зарегистрирован:</th><td><?php echo date('d.m.Y', strtotime($userData['created_at'] ?? 'now')); ?></td></tr>
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
                            <tr><th>Создан:</th><td><?php echo formatBitrixDate($bitrixData['contact']['DATE_CREATE'] ?? $userData['created_at']); ?></td></tr>
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
        <?php endif; ?>

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

        <!-- Проекты -->
        <?php if (!empty($bitrixData['projects'])): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-project-diagram me-2"></i>Проекты (Smart Processes)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Организация</th>
                                <th>Объект</th>
                                <th>Тип системы</th>
                                <th>Местонахождение</th>
                                <th>Дата реализации</th>
                                <th>Статус</th>
                                <th>Клиент</th>
                                <th>Менеджер</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bitrixData['projects'] as $project): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($project['bitrix_id']); ?></td>
                                <td><?php echo htmlspecialchars($project['organization_name']); ?></td>
                                <td><?php echo htmlspecialchars($project['object_name']); ?></td>
                                <td><?php echo htmlspecialchars($project['system_type']); ?></td>
                                <td><?php echo htmlspecialchars($project['location']); ?></td>
                                <td><?php echo $project['implementation_date'] ? formatBitrixDate($project['implementation_date']) : '-'; ?></td>
                                <td><span class="badge bg-<?php echo getProjectStatusColor($project['status']); ?>"><?php echo getProjectStatusText($project['status']); ?></span></td>
                                <td>
                                    <?php
                                    $client = isset($bitrixData['contacts'][$project['client_id']]) ? $bitrixData['contacts'][$project['client_id']] : null;
                                    if ($client):
                                        echo htmlspecialchars($client['name'] . ' ' . $client['last_name']);
                                        if (!empty($client['email'])):
                                            echo '<br><small class="text-muted">' . htmlspecialchars($client['email']) . '</small>';
                                        endif;
                                    else:
                                        echo '-';
                                    endif;
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $manager = isset($bitrixData['managers'][$project['manager_id']]) ? $bitrixData['managers'][$project['manager_id']] : null;
                                    if ($manager):
                                        echo htmlspecialchars($manager['name'] . ' ' . $manager['last_name']);
                                        if (!empty($manager['position'])):
                                            echo '<br><small class="text-muted">' . htmlspecialchars($manager['position']) . '</small>';
                                        endif;
                                    else:
                                        echo '-';
                                    endif;
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                        <small>Показаны локальные данные личного кабинета.</small>
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
