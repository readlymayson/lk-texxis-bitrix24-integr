<?php

/**
 * Главная страница - Личный кабинет с данными из Bitrix24
 */

require_once __DIR__ . '/src/classes/Logger.php';
require_once __DIR__ . '/src/classes/Bitrix24API.php';
require_once __DIR__ . '/src/classes/LocalStorage.php';

$config = require_once __DIR__ . '/src/config/bitrix24.php';

/**
 * Получение читаемого названия поля для отображения
 */
function getFieldDisplayName($fieldKey) {
    $fieldNames = [
        'lk_client_field' => 'Поле ЛК клиента',
        'lk_client_values' => 'Допустимые значения ЛК',
        'email' => 'Email',
        'phone' => 'Телефон',
        'name' => 'Имя',
        'last_name' => 'Фамилия',
        'second_name' => 'Отчество',
        'type_id' => 'Тип клиента',
        'company_id' => 'Компания',
        'title' => 'Название',
        'organization_name' => 'Организация',
        'object_name' => 'Объект',
        'system_types' => 'Типы системы',
        'location' => 'Местонахождение',
        'implementation_date' => 'Дата реализации',
        'status' => 'Статус',
        'client_id' => 'Клиент',
        'manager_id' => 'Менеджер'
    ];

    return $fieldNames[$fieldKey] ?? ucfirst(str_replace('_', ' ', $fieldKey));
}

/**
 * Форматирование значения поля для отображения
 */
function formatFieldValue($value, $fieldKey) {
    if ($value === null || $value === '') {
        return 'Не указано';
    }

    // Для массивов (email, phone)
    if (is_array($value)) {
        if (empty($value)) {
            return 'Не указано';
        }

        // Для email и phone показываем первое значение
        if (isset($value[0]['VALUE'])) {
            return htmlspecialchars($value[0]['VALUE']);
        }

        return htmlspecialchars(implode(', ', $value));
    }

    // Специальное форматирование для дат
    if (strpos($fieldKey, 'date') !== false || strpos($fieldKey, 'DATE') !== false) {
        if (is_numeric($value)) {
            return date('d.m.Y', $value);
        }
        try {
            $date = new DateTime($value);
            return $date->format('d.m.Y');
        } catch (Exception $e) {
            return htmlspecialchars($value);
        }
    }

    return htmlspecialchars($value);
}

/**
 * Загрузка данных контакта и связанных сущностей
 */
function loadContactData($contact, $localStorage, $config) {
    $userData = $contact;
    $bitrixData = [
        'contact' => null,
        'company' => null,
        'projects' => [],
        'managers' => [],
        'contacts' => [],
        'companies' => []
    ];

    if (isset($contact['bitrix_data']) && !empty($contact['bitrix_data'])) {
        $bitrixData['contact'] = $contact['bitrix_data'];
    } else {
        $contactMapping = $config['field_mapping']['contact'] ?? [];
        $bitrixData['contact'] = [
            'ID' => $contact['bitrix_id'],
            ($contactMapping['name'] ?? 'NAME') => $contact['name'],
            ($contactMapping['last_name'] ?? 'LAST_NAME') => $contact['last_name'],
            ($contactMapping['email'] ?? 'EMAIL') => is_array($contact['email']) ? $contact['email'] : [['VALUE' => $contact['email']]],
            ($contactMapping['phone'] ?? 'PHONE') => is_array($contact['phone']) ? $contact['phone'] : [['VALUE' => $contact['phone']]],
            ($contactMapping['post'] ?? 'POST') => $contact['post'] ?? null,
            ($contactMapping['company_title'] ?? 'COMPANY_TITLE') => $contact['company_title'] ?? null,
            'COMPANY_ID' => $contact['company']
        ];
    }

    if (!empty($contact['company'])) {
        $bitrixData['company'] = $localStorage->getCompany($contact['company']);
    }

    $bitrixData['projects'] = $localStorage->getAllProjects();
    $bitrixData['managers'] = $localStorage->getAllManagers();
    $bitrixData['contacts'] = $localStorage->getAllContacts();
    $bitrixData['companies'] = $localStorage->getAllCompanies();

    return ['userData' => $userData, 'bitrixData' => $bitrixData];
}

/**
 * Автоматическое отображение полей сущности из маппинга
 */
function renderEntityFields($entityData, $entityType, $config, $excludeFields = []) {
    if (empty($entityData) || !isset($config['field_mapping'][$entityType])) {
        return;
    }

    $mapping = $config['field_mapping'][$entityType];

    foreach ($mapping as $fieldKey => $fieldCode) {
        // Пропускаем служебные и списочные поля (маппинги со справочными значениями)
        if (in_array($fieldKey, array_merge(['lk_client_field', 'lk_client_values'], $excludeFields)) || is_array($fieldCode)) {
            continue;
        }

        $displayName = getFieldDisplayName($fieldKey);
        $fieldValue = $entityData[$fieldCode] ?? null;
        $formattedValue = formatFieldValue($fieldValue, $fieldKey);

        if ($formattedValue === 'Не указано') {
            continue;
        }

        echo '<tr><th>' . htmlspecialchars($displayName) . ':</th><td>' . $formattedValue . '</td></tr>' . PHP_EOL;
    }
}

$logger = new Logger($config);
$bitrixAPI = new Bitrix24API($config, $logger);
$localStorage = new LocalStorage($logger);

$userData = null;
$bitrixData = [
    'contact' => null,
    'company' => null,
    'projects' => [],
    'managers' => [],
    'contacts' => [],
    'companies' => []
];
$availableContacts = [];

try {
    $availableContacts = $localStorage->getContactsSortedByUpdate(10);
    $lastContact = $localStorage->getLastUpdatedContact();

    if ($lastContact) {
        $result = loadContactData($lastContact, $localStorage, $config);
        $userData = $result['userData'];
        $bitrixData = $result['bitrixData'];
    }
} catch (Exception $e) {
    $logger->error('Error loading last contact data', ['error' => $e->getMessage()]);
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['select_contact'])) {
    $selectedContactId = $_POST['select_contact'] ?? '';

    if (!empty($selectedContactId) && isset($availableContacts[$selectedContactId])) {
        $selectedContact = $availableContacts[$selectedContactId];
        $result = loadContactData($selectedContact, $localStorage, $config);
        $userData = $result['userData'];
        $bitrixData = $result['bitrixData'];
    }
}

$hasBitrixData = $bitrixData['contact'] !== null;

// Грузим все данные из локального хранилища, чтобы показать их на странице даже без выбранного контакта
try {
    if (empty($bitrixData['contacts'])) {
        $bitrixData['contacts'] = $localStorage->getAllContacts();
    }
    if (empty($bitrixData['companies'])) {
        $bitrixData['companies'] = $localStorage->getAllCompanies();
    }
    if (empty($bitrixData['projects'])) {
        $bitrixData['projects'] = $localStorage->getAllProjects();
    }
    if (empty($bitrixData['managers'])) {
        $bitrixData['managers'] = $localStorage->getAllManagers();
    }
} catch (Exception $e) {
    $logger->error('Error loading stored data', ['error' => $e->getMessage()]);
}

$hasAnyData = $hasBitrixData
    || !empty($bitrixData['contacts'])
    || !empty($bitrixData['companies'])
    || !empty($bitrixData['projects'])
    || !empty($bitrixData['managers']);

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
                                        <div class="h4 text-info"><?php echo count($bitrixData['projects'] ?? []); ?></div>
                                        <small class="text-muted">Проектов</small>
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
                            <?php renderEntityFields($bitrixData['contact'], 'contact', $config); ?>
                            <tr><th>ID:</th><td><?php echo htmlspecialchars($bitrixData['contact']['ID'] ?? ''); ?> <span class="bitrix-badge">BX24</span></td></tr>
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
                <?php
                    $company = $bitrixData['company'];
                    $companyId = $company['ID'] ?? $company['id'] ?? '';
                    $companyTitle = $company['TITLE'] ?? $company['title'] ?? '';
                    $companyType = $company['COMPANY_TYPE'] ?? $company['type'] ?? 'Не указан';
                    // Email может быть строкой или массивом с VALUE
                    $companyEmailRaw = $company['EMAIL'] ?? $company['email'] ?? null;
                    if (is_array($companyEmailRaw)) {
                        $companyEmail = $companyEmailRaw[0]['VALUE'] ?? '';
                    } else {
                        $companyEmail = $companyEmailRaw ?? '';
                    }
                    // Телефон аналогично email
                    $companyPhoneRaw = $company['PHONE'] ?? $company['phone'] ?? null;
                    if (is_array($companyPhoneRaw)) {
                        $companyPhone = $companyPhoneRaw[0]['VALUE'] ?? '';
                    } else {
                        $companyPhone = $companyPhoneRaw ?? '';
                    }
                    $companyIndustry = $company['INDUSTRY'] ?? $company['industry'] ?? 'Не указана';
                    $companyEmployees = $company['EMPLOYEES'] ?? $company['employees'] ?? 'Не указано';
                    $companyRevenue = $company['REVENUE'] ?? $company['revenue'] ?? null;
                    $companyAddress = $company['ADDRESS'] ?? $company['address'] ?? 'Не указан';
                    $companyCreated = $company['DATE_CREATE'] ?? $company['created_at'] ?? 'Неизвестно';
                ?>
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-sm data-table">
                            <tr><th>ID:</th><td><?php echo htmlspecialchars($companyId); ?> <span class="bitrix-badge">BX24</span></td></tr>
                            <tr><th>Название:</th><td><?php echo htmlspecialchars($companyTitle); ?></td></tr>
                            <tr><th>Тип:</th><td><?php echo htmlspecialchars($companyType); ?></td></tr>
                            <tr><th>Email:</th><td><?php echo htmlspecialchars($companyEmail ?: 'Не указан'); ?></td></tr>
                            <tr><th>Телефон:</th><td><?php echo htmlspecialchars($companyPhone ?: 'Не указан'); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-sm data-table">
                            <tr><th>Отрасль:</th><td><?php echo htmlspecialchars($companyIndustry); ?></td></tr>
                            <tr><th>Сотрудники:</th><td><?php echo htmlspecialchars($companyEmployees); ?></td></tr>
                            <tr><th>Выручка:</th><td><?php echo $companyRevenue !== null && $companyRevenue !== '' ? number_format((float)$companyRevenue, 0, ',', ' ') . ' ₽' : 'Не указана'; ?></td></tr>
                            <tr><th>Адрес:</th><td><?php echo htmlspecialchars($companyAddress); ?></td></tr>
                            <tr><th>Создан:</th><td><?php echo formatBitrixDate($companyCreated); ?></td></tr>
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
                                <th>Типы системы</th>
                                <th>Местонахождение</th>
                                <th>Дата реализации</th>
                                <th>Тип запросов</th>
                                <th>Перечень оборуд.</th>
                                <th>Конкуренты</th>
                                <th>Тех. описание</th>
                                <th>Маркет. скидка</th>
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
                                <td>
                                    <?php 
                                    if (!empty($project['system_types'])) {
                                        if (is_array($project['system_types'])) {
                                            echo htmlspecialchars(implode(', ', $project['system_types']));
                                        } else {
                                            echo htmlspecialchars($project['system_types']);
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($project['location']); ?></td>
                                <td><?php echo $project['implementation_date'] ? formatBitrixDate($project['implementation_date']) : '-'; ?></td>
                                <td><?php echo !empty($project['request_type']) ? htmlspecialchars($project['request_type']) : '-'; ?></td>
                                <td>
                                    <?php 
                                    if (!empty($project['equipment_list']) && is_array($project['equipment_list'])): 
                                        echo '<small>';
                                        foreach ($project['equipment_list'] as $file):
                                            $fileName = $file['name'] ?? 'Файл #' . ($file['id'] ?? '?');
                                            if (!empty($file['url'])):
                                                echo '<a href="' . htmlspecialchars($file['url']) . '" target="_blank" class="text-decoration-none">';
                                                echo '<i class="fas fa-file-download"></i> ' . htmlspecialchars($fileName);
                                                echo '</a>';
                                            else:
                                                echo '<i class="fas fa-file"></i> ' . htmlspecialchars($fileName);
                                            endif;
                                            echo '<br>';
                                        endforeach;
                                        echo '</small>';
                                    else:
                                        echo '-';
                                    endif;
                                    ?>
                                </td>
                                <td><?php echo !empty($project['competitors']) ? htmlspecialchars($project['competitors']) : '-'; ?></td>
                                <?php $technicalDescription = $project['technical_description'] ?? ''; ?>
                                <td><?php echo !empty($technicalDescription) ? nl2br(htmlspecialchars($technicalDescription)) : '-'; ?></td>
                                <td><?php echo !empty($project['marketing_discount']) ? '<span class="badge bg-success">Да</span>' : '<span class="badge bg-secondary">Нет</span>'; ?></td>
                                <td><span class="badge bg-<?php echo getProjectStatusColor($project['status']); ?>"><?php echo getProjectStatusText($project['status']); ?></span></td>
                                <td>
                                    <?php
                                    $client = null;
                                    if (!empty($project['client_id'])) {
                                        foreach ($bitrixData['contacts'] as $contact) {
                                            if (($contact['bitrix_id'] ?? '') == $project['client_id']) {
                                                $client = $contact;
                                                break;
                                            }
                                        }
                                    }
                                    if ($client):
                                        echo htmlspecialchars(($client['name'] ?? '') . ' ' . ($client['last_name'] ?? ''));
                                        $clientEmail = is_array($client['email'] ?? null) ? ($client['email'][0]['VALUE'] ?? '') : ($client['email'] ?? '');
                                        if (!empty($clientEmail)):
                                            echo '<br><small class="text-muted">' . htmlspecialchars($clientEmail) . '</small>';
                                        endif;
                                    else:
                                        echo '-';
                                    endif;
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $manager = null;
                                    if (!empty($project['manager_id'])) {
                                        foreach ($bitrixData['managers'] as $mgr) {
                                            if (($mgr['bitrix_id'] ?? '') == $project['manager_id']) {
                                                $manager = $mgr;
                                                break;
                                            }
                                        }
                                    }
                                    if ($manager):
                                        echo htmlspecialchars(($manager['name'] ?? '') . ' ' . ($manager['last_name'] ?? ''));
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

        <!-- Менеджеры -->
        <?php if (!empty($bitrixData['managers'])): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-users me-2"></i>Менеджеры (Bitrix24)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                        <th>Фото</th>
                                <th>ФИО</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                        <th>Мессенджеры</th>
                                <th>Должность</th>
                                <th>Создан</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bitrixData['managers'] as $manager): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($manager['bitrix_id']); ?></td>
                                        <td>
                                            <?php if (!empty($manager['photo'])): ?>
                                                <img src="<?php echo htmlspecialchars($manager['photo']); ?>" alt="Фото <?php echo htmlspecialchars($manager['name'] ?? ''); ?>" class="rounded-circle" width="48" height="48">
                                            <?php else: ?>
                                                <span class="text-muted">Нет</span>
                                            <?php endif; ?>
                                        </td>
                                <td><?php echo htmlspecialchars(($manager['name'] ?? '') . ' ' . ($manager['last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($manager['email'] ?? 'Не указан'); ?></td>
                                <td><?php echo htmlspecialchars($manager['phone'] ?? 'Не указан'); ?></td>
                                        <td>
                                            <?php if (!empty($manager['messengers'])): ?>
                                                <?php foreach ($manager['messengers'] as $messenger => $link): ?>
                                                    <a href="<?php echo htmlspecialchars($link); ?>" target="_blank" class="badge bg-secondary text-decoration-none me-1">
                                                        <?php echo htmlspecialchars(ucfirst($messenger)); ?>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">Не указаны</span>
                                            <?php endif; ?>
                                        </td>
                                <td><?php echo htmlspecialchars($manager['position'] ?? 'Не указана'); ?></td>
                                <td><?php echo formatBitrixDate($manager['created_at'] ?? 'Неизвестно'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Все компании -->
        <?php if (!empty($bitrixData['companies'])): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-building me-2"></i>Все компании (Bitrix24)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Тип</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Отрасль</th>
                                <th>Создан</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bitrixData['companies'] as $company): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['id'] ?? $company['ID'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($company['title'] ?? $company['TITLE'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($company['type'] ?? $company['COMPANY_TYPE'] ?? 'Не указан'); ?></td>
                                <td><?php 
                                    $companyEmail = '';
                                    if (isset($company['email'])) {
                                        if (is_array($company['email'])) {
                                            $companyEmail = $company['email'][0]['VALUE'] ?? '';
                                        } else {
                                            $companyEmail = $company['email'];
                                        }
                                    } elseif (isset($company['EMAIL'])) {
                                        if (is_array($company['EMAIL'])) {
                                            $companyEmail = $company['EMAIL'][0]['VALUE'] ?? '';
                                        } else {
                                            $companyEmail = $company['EMAIL'];
                                        }
                                    }
                                    echo htmlspecialchars($companyEmail ?: 'Не указан');
                                ?></td>
                                <td><?php 
                                    $companyPhone = '';
                                    if (isset($company['phone'])) {
                                        if (is_array($company['phone'])) {
                                            $companyPhone = $company['phone'][0]['VALUE'] ?? '';
                                        } else {
                                            $companyPhone = $company['phone'];
                                        }
                                    } elseif (isset($company['PHONE'])) {
                                        if (is_array($company['PHONE'])) {
                                            $companyPhone = $company['PHONE'][0]['VALUE'] ?? '';
                                        } else {
                                            $companyPhone = $company['PHONE'];
                                        }
                                    }
                                    echo htmlspecialchars($companyPhone ?: 'Не указан');
                                ?></td>
                                <td><?php echo htmlspecialchars($company['industry'] ?? $company['INDUSTRY'] ?? 'Не указана'); ?></td>
                                <td><?php echo formatBitrixDate($company['created_at'] ?? $company['DATE_CREATE'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Все контакты -->
        <?php if (!empty($bitrixData['contacts'])): ?>
        <div class="card mb-4">
            <div class="profile-header card-header">
                <h4 class="mb-0"><i class="fas fa-address-book me-2"></i>Все контакты (Bitrix24)</h4>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>ФИО</th>
                                <th>Email</th>
                                <th>Телефон</th>
                                <th>Тип</th>
                                <th>Компания</th>
                                <th>Статус</th>
                                <th>Обновлен</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bitrixData['contacts'] as $contact): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($contact['bitrix_id'] ?? $contact['id'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars(trim(($contact['name'] ?? '') . ' ' . ($contact['second_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))); ?></td>
                                <td><?php 
                                    $contactEmail = '';
                                    if (isset($contact['email'])) {
                                        if (is_array($contact['email'])) {
                                            $contactEmail = $contact['email'][0]['VALUE'] ?? '';
                                        } else {
                                            $contactEmail = $contact['email'];
                                        }
                                    }
                                    echo htmlspecialchars($contactEmail ?: 'Не указан');
                                ?></td>
                                <td><?php 
                                    $contactPhone = '';
                                    if (isset($contact['phone'])) {
                                        if (is_array($contact['phone'])) {
                                            $contactPhone = $contact['phone'][0]['VALUE'] ?? '';
                                        } else {
                                            $contactPhone = $contact['phone'];
                                        }
                                    }
                                    echo htmlspecialchars($contactPhone ?: 'Не указан');
                                ?></td>
                                <td><?php echo htmlspecialchars($contact['type_id'] ?? 'Не указан'); ?></td>
                                <td><?php 
                                    $companyName = '-';
                                    if (!empty($contact['company'])) {
                                        $company = $localStorage->getCompany($contact['company']);
                                        if ($company) {
                                            $companyName = htmlspecialchars($company['title'] ?? $company['TITLE'] ?? $contact['company']);
                                        } else {
                                            $companyName = htmlspecialchars($contact['company']);
                                        }
                                    }
                                    echo $companyName;
                                ?></td>
                                <td><span class="badge bg-<?php echo ($contact['status'] ?? '') === 'active' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($contact['status'] ?? 'Не указан'); ?></span></td>
                                <td><?php echo formatBitrixDate($contact['updated_at'] ?? $contact['created_at'] ?? ''); ?></td>
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

