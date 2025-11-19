<?php
# -*- coding: utf-8 -*-

/**
 * Комплексный файл тестирования проекта интеграции Битрикс24
 * Полная проверка работоспособности всех компонентов системы
 */

class ComprehensiveTester
{
    private $config;
    private $results = [];
    private $logger;
    private $startTime;
    private $totalTests = 0;
    private $passedTests = 0;

    public function __construct()
    {
        $this->startTime = microtime(true);

        // Загрузка конфигурации
        $this->config = require_once __DIR__ . '/../src/config/bitrix24.php';

        // Инициализация логгера
        if (class_exists('Logger')) {
            $this->logger = new Logger($this->config);
        }
    }

    /**
     * Запуск всех тестов
     */
    public function runAllTests()
    {
        $this->printHeader();

        // 1. Тесты конфигурации и зависимостей
        $this->testConfiguration();

        // 2. Тесты файловой системы
        $this->testFileSystem();

        // 3. Тесты PHP и расширений
        $this->testPHPSupport();

        // 4. Тесты классов
        $this->testClasses();

        // 5. Тесты локального хранилища
        $this->testStorage();

        // 6. Тесты API клиентов
        $this->testAPI();

        // 7. Тесты веб-интерфейса
        $this->testWebInterface();

        // 8. Тесты безопасности
        $this->testSecurity();

        // 9. Тесты производительности
        $this->testPerformance();

        // 10. Тесты интеграции
        $this->testIntegration();

        // 11. Запуск существующих тестов
        $this->runExistingTests();

        $this->printSummary();
    }

    /**
     * Тесты конфигурации и зависимостей
     */
    private function testConfiguration()
    {
        $this->sectionHeader("1. ТЕСТЫ КОНФИГУРАЦИИ И ЗАВИСИМОСТЕЙ");

        // Проверка файла конфигурации
        $this->test("Проверка файла конфигурации", function() {
            return file_exists(__DIR__ . '/../src/config/bitrix24.php');
        });

        // Проверка переменных окружения
        $this->test("Проверка переменных окружения", function() {
            return class_exists('EnvLoader');
        });

        // Проверка необходимых полей конфигурации
        $this->test("Проверка обязательных полей конфигурации", function() {
            $required = ['logging', 'field_mapping', 'events'];
            foreach ($required as $field) {
                if (!isset($this->config[$field])) {
                    return false;
                }
            }
            return true;
        });

        // Проверка маппинга полей
        $this->test("Проверка маппинга полей", function() {
            return isset($this->config['field_mapping']['contact']) &&
                   isset($this->config['field_mapping']['company']);
        });

        // Проверка настроек логирования
        $this->test("Проверка настроек логирования", function() {
            return isset($this->config['logging']['level']) &&
                   isset($this->config['logging']['file']);
        });
    }

    /**
     * Тесты файловой системы
     */
    private function testFileSystem()
    {
        $this->sectionHeader("2. ТЕСТЫ ФАЙЛОВОЙ СИСТЕМЫ");

        // Проверка основных директорий
        $directories = [
            'src/classes' => 'Директория классов',
            'src/config' => 'Директория конфигурации',
            'src/logs' => 'Директория логов',
            'src/data' => 'Директория данных',
            'src/webhooks' => 'Директория webhook обработчиков',
            'tests' => 'Директория тестов'
        ];

        foreach ($directories as $dir => $description) {
            $this->test("Проверка директории: $description", function() use ($dir) {
                return is_dir(__DIR__ . '/../' . $dir);
            });
        }

        // Проверка прав доступа на запись
        $writableDirs = ['src/logs', 'src/data'];
        foreach ($writableDirs as $dir) {
            $this->test("Права на запись в $dir", function() use ($dir) {
                $fullPath = __DIR__ . '/../' . $dir;
                return is_dir($fullPath) && is_writable($fullPath);
            });
        }

        // Проверка основных файлов
        $files = [
            'index.php' => 'Главная страница',
            'src/webhooks/bitrix24.php' => 'Обработчик webhook',
            'src/classes/Logger.php' => 'Класс логирования',
            'src/classes/Bitrix24API.php' => 'API Битрикс24',
            'src/classes/LocalStorage.php' => 'Локальное хранилище'
        ];

        foreach ($files as $file => $description) {
            $this->test("Проверка файла: $description", function() use ($file) {
                return file_exists(__DIR__ . '/../' . $file);
            });
        }
    }

    /**
     * Тесты PHP и расширений
     */
    private function testPHPSupport()
    {
        $this->sectionHeader("3. ТЕСТЫ PHP И РАСШИРЕНИЙ");

        // Проверка версии PHP
        $this->test("Версия PHP 7.4+", function() {
            return version_compare(PHP_VERSION, '7.4.0', '>=');
        });

        // Проверка необходимых расширений
        $extensions = ['curl', 'json', 'mbstring'];
        foreach ($extensions as $ext) {
            $this->test("Расширение PHP: $ext", function() use ($ext) {
                return extension_loaded($ext);
            });
        }

        // Проверка функций
        $functions = ['json_encode', 'json_decode', 'curl_init', 'file_get_contents'];
        foreach ($functions as $func) {
            $this->test("Функция PHP: $func", function() use ($func) {
                return function_exists($func);
            });
        }

        // Проверка максимального времени выполнения
        $this->test("Максимальное время выполнения", function() {
            $maxTime = ini_get('max_execution_time');
            return $maxTime == 0 || $maxTime >= 30;
        });
    }

    /**
     * Тесты классов
     */
    private function testClasses()
    {
        $this->sectionHeader("4. ТЕСТЫ КЛАССОВ");

        // Проверка загрузки классов
        $classes = [
            'Logger' => 'src/classes/Logger.php',
            'Bitrix24API' => 'src/classes/Bitrix24API.php',
            'LocalStorage' => 'src/classes/LocalStorage.php',
            'LKAPI' => 'src/classes/LKAPI.php'
        ];

        foreach ($classes as $class => $file) {
            $this->test("Загрузка класса: $class", function() use ($class, $file) {
                require_once __DIR__ . '/../' . $file;
                return class_exists($class);
            });
        }

        // Тест создания экземпляров классов
        $this->test("Создание экземпляра Logger", function() {
            if (!class_exists('Logger')) return false;
            try {
                $logger = new Logger($this->config);
                return $logger instanceof Logger;
            } catch (Exception $e) {
                return false;
            }
        });

        $this->test("Создание экземпляра LocalStorage", function() {
            if (!class_exists('LocalStorage') || !class_exists('Logger')) return false;
            try {
                $logger = new Logger($this->config);
                $storage = new LocalStorage($logger);
                return $storage instanceof LocalStorage;
            } catch (Exception $e) {
                return false;
            }
        });
    }

    /**
     * Тесты локального хранилища
     */
    private function testStorage()
    {
        $this->sectionHeader("5. ТЕСТЫ ЛОКАЛЬНОГО ХРАНИЛИЩА");

        if (!class_exists('LocalStorage')) {
            $this->test("Пропуск тестов LocalStorage - класс не найден", function() { return false; });
            return;
        }

        try {
            $logger = new Logger($this->config);
            $storage = new LocalStorage($logger);

            // Тест создания ЛК
            $this->test("Создание личного кабинета", function() use ($storage) {
                $testData = [
                    'ID' => 'test_' . time(),
                    'NAME' => 'Тестовый',
                    'LAST_NAME' => 'Пользователь',
                    'EMAIL' => [['VALUE' => 'test@example.com']],
                    'PHONE' => [['VALUE' => '+7 (999) 123-45-67']]
                ];

                $result = $storage->createLK($testData);
                return isset($result['success']) && $result['success'];
            });

            // Тест получения контактов
            $this->test("Получение списка контактов", function() use ($storage) {
                $contacts = $storage->getContactsSortedByUpdate(10);
                return is_array($contacts);
            });

            // Тест получения последнего контакта
            $this->test("Получение последнего контакта", function() use ($storage) {
                $contact = $storage->getLastUpdatedContact();
                return $contact !== null;
            });

        } catch (Exception $e) {
            $this->test("Ошибка при тестировании LocalStorage: " . $e->getMessage(), function() { return false; });
        }
    }

    /**
     * Тесты API клиентов
     */
    private function testAPI()
    {
        $this->sectionHeader("6. ТЕСТЫ API КЛИЕНТОВ");

        if (!class_exists('Bitrix24API')) {
            $this->test("Пропуск тестов Bitrix24API - класс не найден", function() { return false; });
            return;
        }

        try {
            $logger = new Logger($this->config);
            $api = new Bitrix24API($this->config, $logger);

            // Тест определения типа события
            $this->test("Определение типа события", function() use ($api) {
                $type = $api->getEntityTypeFromEvent('ONCRMCONTACTUPDATE');
                return $type === 'contact';
            });

            // Тест валидации webhook
            $this->test("Валидация webhook запроса", function() use ($api) {
                $headers = [
                    'User-Agent' => 'Bitrix24 Webhook Engine',
                    'Content-Type' => 'application/json'
                ];
                $body = '{"event":"test","data":{"test":true},"auth":{"application_token":""}}';

                $result = $api->validateWebhookRequest($headers, $body);
                return is_array($result);
            });

        } catch (Exception $e) {
            $this->test("Ошибка при тестировании Bitrix24API: " . $e->getMessage(), function() { return false; });
        }
    }

    /**
     * Тесты веб-интерфейса
     */
    private function testWebInterface()
    {
        $this->sectionHeader("7. ТЕСТЫ ВЕБ-ИНТЕРФЕЙСА");

        // Проверка доступности главной страницы
        $this->test("Доступность главной страницы", function() {
            $url = 'http://localhost:8000/index.php';
            return $this->checkUrl($url);
        });

        // Проверка доступности дашборда
        $this->test("Доступность дашборда интеграции", function() {
            $url = 'http://localhost:8000/integration_dashboard.php';
            return $this->checkUrl($url);
        });

        // Проверка webhook обработчика
        $this->test("Доступность webhook обработчика", function() {
            $webhookPath = __DIR__ . '/../src/webhooks/bitrix24.php';
            return file_exists($webhookPath) && is_readable($webhookPath);
        });
    }

    /**
     * Тесты безопасности
     */
    private function testSecurity()
    {
        $this->sectionHeader("8. ТЕСТЫ БЕЗОПАСНОСТИ");

        // Проверка файла .env
        $this->test("Файл .env не доступен извне", function() {
            $envFile = __DIR__ . '/../.env';
            if (!file_exists($envFile)) return true; // Если файла нет, то хорошо

            // Проверяем, что файл не доступен по HTTP
            $url = 'http://localhost:8000/.env';
            $context = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 5]
            ]);

            $result = @file_get_contents($url, false, $context);
            return $result === false || strpos($result, '404') !== false;
        });

        // Проверка прав доступа к логам
        $this->test("Логи не доступны публично", function() {
            $logUrl = 'http://localhost:8000/src/logs/';
            return !$this->checkUrl($logUrl);
        });

        // Проверка webhook на предмет SQL инъекций
        $this->test("Webhook устойчив к SQL инъекциям", function() {
            $webhookPath = __DIR__ . '/../src/webhooks/bitrix24.php';
            if (!file_exists($webhookPath)) return false;

            $content = file_get_contents($webhookPath);
            // Проверяем, что нет прямых SQL запросов без подготовки
            return strpos($content, 'mysql_query') === false &&
                   strpos($content, 'mysqli_query') === false;
        });
    }

    /**
     * Тесты производительности
     */
    private function testPerformance()
    {
        $this->sectionHeader("9. ТЕСТЫ ПРОИЗВОДИТЕЛЬНОСТИ");

        // Тест времени загрузки классов
        $this->test("Время загрузки классов", function() {
            $start = microtime(true);

            require_once __DIR__ . '/../src/classes/Logger.php';
            require_once __DIR__ . '/../src/classes/Bitrix24API.php';
            require_once __DIR__ . '/../src/classes/LocalStorage.php';

            $end = microtime(true);
            $loadTime = ($end - $start) * 1000; // в миллисекундах

            return $loadTime < 500; // Менее 500мс
        });

        // Тест размера файлов логов
        $this->test("Размер файлов логов", function() {
            $logDir = __DIR__ . '/../src/logs/';
            if (!is_dir($logDir)) return true;

            $totalSize = 0;
            $files = glob($logDir . '*.log');
            foreach ($files as $file) {
                $totalSize += filesize($file);
            }

            // Максимальный размер - 50MB
            return $totalSize < 50 * 1024 * 1024;
        });
    }

    /**
     * Тесты интеграции
     */
    private function testIntegration()
    {
        $this->sectionHeader("10. ТЕСТЫ ИНТЕГРАЦИИ");

        // Тест полного цикла обработки webhook
        $this->test("Тест полного цикла webhook", function() {
            if (!class_exists('Bitrix24API') || !class_exists('LocalStorage')) {
                return false;
            }

            try {
                $logger = new Logger($this->config);
                $api = new Bitrix24API($this->config, $logger);
                $storage = new LocalStorage($logger);

                // Создаем тестовые данные
                $headers = [
                    'User-Agent' => 'Bitrix24 Webhook',
                    'Content-Type' => 'application/json'
                ];

                $body = json_encode([
                    'event' => 'ONCRMCONTACTUPDATE',
                    'data' => [
                        'FIELDS' => [
                            'ID' => 'integration_test_' . time(),
                            'NAME' => 'Интеграционный',
                            'LAST_NAME' => 'Тест',
                            'EMAIL' => [['VALUE' => 'integration@test.com']],
                            'UF_CRM_CONTACT_LK_CLIENT' => 'Y'
                        ]
                    ]
                ]);

                // Валидируем webhook
                $validated = $api->validateWebhookRequest($headers, $body);
                if (!$validated) return false;

                // Создаем ЛК
                $contactData = [
                    'ID' => $validated['data']['FIELDS']['ID'],
                    'NAME' => $validated['data']['FIELDS']['NAME'],
                    'LAST_NAME' => $validated['data']['FIELDS']['LAST_NAME'],
                    'EMAIL' => [['VALUE' => 'integration@test.com']]
                ];

                $result = $storage->createLK($contactData);
                return isset($result['success']) && $result['success'];

            } catch (Exception $e) {
                return false;
            }
        });
    }

    /**
     * Запуск существующих тестов
     */
    private function runExistingTests()
    {
        $this->sectionHeader("11. ЗАПУСК СУЩЕСТВУЮЩИХ ТЕСТОВ");

        $existingTests = [
            'tests/test_integration.php' => 'Основные тесты интеграции',
            'tests/test_validation.php' => 'Тесты валидации webhook',
            'tests/test_edge_cases.php' => 'Тесты edge cases',
            'tests/check_mapping.php' => 'Проверка маппинга',
            'tests/check_network.php' => 'Проверка сети',
            'tests/check_web.php' => 'Проверка веб-интерфейса'
        ];

        foreach ($existingTests as $testFile => $description) {
            $this->test("Запуск: $description", function() use ($testFile) {
                $fullPath = __DIR__ . '/../' . $testFile;
                if (!file_exists($fullPath)) return false;

                $output = shell_exec("php $fullPath 2>&1");
                return strpos($output, 'ПРОЙДЕНО') !== false ||
                       strpos($output, 'SUCCESS') !== false ||
                       strpos($output, 'OK') !== false;
            });
        }
    }

    /**
     * Выполнение отдельного теста
     */
    private function test($description, $callback)
    {
        $this->totalTests++;
        echo "  Тестирование: $description... ";

        try {
            $result = $callback();
            if ($result) {
                $this->passedTests++;
                echo "✓ ПРОЙДЕН\n";
            } else {
                echo "✗ ПРОВАЛЕН\n";
            }

            $this->results[] = [
                'description' => $description,
                'passed' => $result
            ];

        } catch (Exception $e) {
            echo "✗ ОШИБКА: " . $e->getMessage() . "\n";
            $this->results[] = [
                'description' => $description,
                'passed' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Проверка доступности URL
     */
    private function checkUrl($url)
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true
            ]
        ]);

        $result = @file_get_contents($url, false, $context);
        return $result !== false;
    }

    /**
     * Заголовок секции
     */
    private function sectionHeader($title)
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo $title . "\n";
        echo str_repeat("=", 80) . "\n";
    }

    /**
     * Заголовок отчета
     */
    private function printHeader()
    {
        echo str_repeat("=", 100) . "\n";
        echo "КОМПЛЕКСНОЕ ТЕСТИРОВАНИЕ ПРОЕКТА ИНТЕГРАЦИИ БИТРИКС24\n";
        echo "Личный кабинет с синхронизацией данных\n";
        echo str_repeat("=", 100) . "\n";
        echo "Время запуска: " . date('Y-m-d H:i:s') . "\n";
        echo "Версия PHP: " . PHP_VERSION . "\n";
        echo "Операционная система: " . PHP_OS . "\n";
        echo str_repeat("=", 100) . "\n\n";
    }

    /**
     * Итоговый отчет
     */
    private function printSummary()
    {
        $endTime = microtime(true);
        $executionTime = round($endTime - $this->startTime, 2);

        echo "\n" . str_repeat("=", 100) . "\n";
        echo "ИТОГОВЫЙ ОТЧЕТ ТЕСТИРОВАНИЯ\n";
        echo str_repeat("=", 100) . "\n\n";

        echo "Время выполнения: {$executionTime} секунд\n";
        echo "Всего тестов: {$this->totalTests}\n";
        echo "Пройдено: {$this->passedTests}\n";
        echo "Провалено: " . ($this->totalTests - $this->passedTests) . "\n\n";

        // Детальный отчет по секциям
        echo "ДЕТАЛЬНЫЕ РЕЗУЛЬТАТЫ:\n";
        echo str_repeat("-", 100) . "\n";

        $failedTests = array_filter($this->results, function($test) {
            return !$test['passed'];
        });

        if (empty($failedTests)) {
            echo "✓ ВСЕ ТЕСТЫ ПРОЙДЕНЫ УСПЕШНО!\n\n";
            echo "🎉 ПРОЕКТ ГОТОВ К ИСПОЛЬЗОВАНИЮ!\n\n";
        } else {
            echo "✗ ПРОВАЛЕННЫЕ ТЕСТЫ:\n\n";
            foreach ($failedTests as $test) {
                echo "  - {$test['description']}\n";
                if (isset($test['error'])) {
                    echo "    Ошибка: {$test['error']}\n";
                }
                echo "\n";
            }
        }

        // Рекомендации
        echo "РЕКОМЕНДАЦИИ:\n";
        echo str_repeat("-", 100) . "\n";

        if ($this->passedTests < $this->totalTests) {
            echo "1. Исправьте проваленные тесты перед запуском в продакшен\n";
            echo "2. Проверьте логи в src/logs/ для получения подробной информации\n";
            echo "3. Убедитесь в корректности конфигурации в src/config/bitrix24.php\n";
        }

        echo "1. Регулярно запускайте комплексное тестирование\n";
        echo "2. Мониторьте логи на наличие ошибок\n";
        echo "3. Проверяйте доступность webhook URL для Битрикс24\n";
        echo "4. Следите за обновлениями API Битрикс24\n\n";

        // Сохранение результатов в файл
        $this->saveResultsToFile();

        echo str_repeat("=", 100) . "\n";
    }

    /**
     * Сохранение результатов в файл
     */
    private function saveResultsToFile()
    {
        $resultsFile = __DIR__ . '/../src/logs/comprehensive_test_' . date('Y-m-d_H-i-s') . '.log';

        $content = "КОМПЛЕКСНОЕ ТЕСТИРОВАНИЕ ПРОЕКТА\n";
        $content .= "Дата: " . date('Y-m-d H:i:s') . "\n";
        $content .= "Пройдено: {$this->passedTests}/{$this->totalTests}\n\n";

        $content .= "ДЕТАЛЬНЫЕ РЕЗУЛЬТАТЫ:\n";
        foreach ($this->results as $result) {
            $status = $result['passed'] ? 'ПРОЙДЕН' : 'ПРОВАЛЕН';
            $content .= "[{$status}] {$result['description']}\n";
            if (isset($result['error'])) {
                $content .= "  Ошибка: {$result['error']}\n";
            }
        }

        file_put_contents($resultsFile, $content);

        echo "Результаты сохранены в: $resultsFile\n\n";
    }
}

// Запуск тестирования
try {
    $tester = new ComprehensiveTester();
    $tester->runAllTests();
} catch (Exception $e) {
    echo "КРИТИЧЕСКАЯ ОШИБКА ТЕСТИРОВАНИЯ: " . $e->getMessage() . "\n";
    echo "Проверьте структуру проекта и права доступа.\n";
}

?>
