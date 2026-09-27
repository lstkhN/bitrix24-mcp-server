#!/usr/bin/env php
<?php

/**
 * Тест подключения к MCP серверу
 * Работает только ПОСЛЕ установки модуля через админку Битрикс
 */

// Определяем константы для CLI режима
$bitrixDefine = 'define';
$bitrixDefine("NO_KEEP_STATISTIC", true);
$bitrixDefine("NO_AGENT_STATISTIC", true);
$bitrixDefine("NO_AGENT_CHECK", true);
$bitrixDefine("DisableEventsCheck", true);
$bitrixDefine("STOP_STATISTICS", true);
$bitrixDefine("BX_NO_ACCELERATOR_RESET", true);
$bitrixDefine("NOT_CHECK_PERMISSIONS", true);

// Определяем DOCUMENT_ROOT
$_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 4);

// Подключаем Битрикс
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$moduleId = 'bitrix24.mcp';

// Проверяем установлен ли модуль
if (!\Bitrix\Main\ModuleManager::isModuleInstalled($moduleId)) {
    echo "\n";
    echo "❌ ОШИБКА: Модуль '$moduleId' не установлен.\n\n";
    echo "Для установки модуля:\n";
    echo "1. Откройте в браузере: " . $_SERVER['DOCUMENT_ROOT'] . "/bitrix/admin/partner_modules.php\n";
    echo "2. Найдите 'MCP Server для Битрикс24'\n";
    echo "3. Нажмите кнопку 'Установить'\n";
    echo "4. После установки запустите этот скрипт снова\n\n";
    exit(1);
}

// Загружаем модуль
\Bitrix\Main\Loader::includeModule($moduleId);

echo "=" . str_repeat("=", 78) . "=\n";
echo "  Тест MCP сервера Битрикс24\n";
echo "=" . str_repeat("=", 78) . "=\n\n";

try {
    // 1. Проверка модуля
    echo "✓ Модуль bitrix24.mcp загружен\n";

    // 2. Проверка токена
    $token = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'auth_token', '');

    if (empty($token)) {
        echo "❌ Токен авторизации не настроен\n";
        exit(1);
    }

    echo "✓ Токен авторизации настроен\n";

    // 3. Создание сервера
    $server = new \Bitrix24\Mcp\Server();
    echo "✓ MCP сервер создан\n";

    // 4. Регистрация инструментов
    $tools = [
        new \Bitrix24\Mcp\Tools\DatabaseSchema(),
        new \Bitrix24\Mcp\Tools\CrmSchema(),
        new \Bitrix24\Mcp\Tools\ModulesList(),
        new \Bitrix24\Mcp\Tools\CodeSearch(),
        new \Bitrix24\Mcp\Tools\ClassInspector(),
        new \Bitrix24\Mcp\Tools\ComponentsList(),
        new \Bitrix24\Mcp\Tools\EventHandlers(),
        new \Bitrix24\Mcp\Tools\FileReader(),
        new \Bitrix24\Mcp\Tools\SqlQuery(),
    ];

    foreach ($tools as $tool) {
        $server->registerTool($tool);
    }

    echo "✓ Зарегистрировано " . count($tools) . " инструментов\n";

    // 5. Регистрация ресурсов
    $resources = [
        new \Bitrix24\Mcp\Resources\ProjectStructure(),
        new \Bitrix24\Mcp\Resources\D7Documentation(),
        new \Bitrix24\Mcp\Resources\CodingStandards(),
    ];

    foreach ($resources as $resource) {
        $server->registerResource($resource);
    }

    echo "✓ Зарегистрировано " . count($resources) . " ресурсов\n";

    // 6. Тест initialize
    $initResponse = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2024-11-05',
            'clientInfo' => [
                'name' => 'test-client',
                'version' => '1.0.0',
            ],
        ],
    ]);

    if (isset($initResponse['result']['protocolVersion'])) {
        echo "✓ Initialize успешно: " . $initResponse['result']['protocolVersion'] . "\n";
    } else {
        echo "❌ Initialize failed\n";
        print_r($initResponse);
        exit(1);
    }

    // 7. Тест tools/list
    $toolsResponse = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]);

    if (isset($toolsResponse['result']['tools'])) {
        echo "✓ tools/list успешно: " . count($toolsResponse['result']['tools']) . " инструментов\n";
    } else {
        echo "❌ tools/list failed\n";
        exit(1);
    }

    // 8. Тест resources/list
    $resourcesResponse = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'resources/list',
    ]);

    if (isset($resourcesResponse['result']['resources'])) {
        echo "✓ resources/list успешно: " . count($resourcesResponse['result']['resources']) . " ресурсов\n";
    } else {
        echo "❌ resources/list failed\n";
        exit(1);
    }

    // 9. Тест вызова инструмента (ping)
    $pingResponse = $server->handleRequest([
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'ping',
    ]);

    if (isset($pingResponse['result']['status']) && $pingResponse['result']['status'] === 'pong') {
        echo "✓ Ping успешно\n";
    } else {
        echo "❌ Ping failed\n";
        exit(1);
    }

    // 10. Проверка логов
    $logPath = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/mcp.log';

    if (file_exists($logPath)) {
        echo "✓ Логи доступны: $logPath\n";
    } else {
        echo "⚠  Логи не найдены (возможно, логирование отключено)\n";
    }

    echo "\n";
    echo "=" . str_repeat("=", 78) . "=\n";
    echo "  ✅ Все тесты пройдены успешно!\n";
    echo "=" . str_repeat("=", 78) . "=\n\n";

    echo "📋 Информация о сервере:\n\n";
    echo "  Версия протокола: " . $initResponse['result']['protocolVersion'] . "\n";
    echo "  Инструментов: " . count($toolsResponse['result']['tools']) . "\n";
    echo "  Ресурсов: " . count($resourcesResponse['result']['resources']) . "\n";
    echo "  Токен: " . substr($token, 0, 16) . "...\n\n";

    echo "📝 Доступные инструменты:\n\n";

    foreach ($toolsResponse['result']['tools'] as $tool) {
        echo "  - {$tool['name']}: {$tool['description']}\n";
    }

    echo "\n📚 Доступные ресурсы:\n\n";

    foreach ($resourcesResponse['result']['resources'] as $resource) {
        echo "  - {$resource['uri']}: {$resource['description']}\n";
    }

    echo "\n💡 Следующие шаги:\n\n";
    echo "  1. Настройте .mcp.json: php cli/generate-config.php\n";
    echo "  2. Сгенерируйте CLAUDE.md: php cli/generate-map.php\n";
    echo "  3. Подключите Claude Code или Cursor\n\n";
} catch (\Exception $e) {
    echo "\n❌ Ошибка: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
