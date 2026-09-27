#!/usr/bin/env php
<?php

/**
 * MCP Server stdio точка входа
 * Используется для SSH подключений
 * Работает только ПОСЛЕ установки модуля через админку Битрикс
 *
 * Использование:
 * php /path/to/cli/server.php
 *
 * Для Claude Code в .mcp.json:
 * {
 *   "mcpServers": {
 *     "bitrix": {
 *       "command": "ssh",
 *       "args": ["user@server", "php /path/to/cli/server.php"]
 *     }
 *   }
 * }
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
    fwrite(STDERR, "ERROR: Module '$moduleId' is not installed.\n");
    fwrite(STDERR, "Please install it via Bitrix admin panel first.\n");
    exit(1);
}

// Загружаем модуль
\Bitrix\Main\Loader::includeModule($moduleId);

// Обработка сигналов
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () {
        exit(0);
    });

    pcntl_signal(SIGINT, function () {
        exit(0);
    });
}

try {
    // Создаём MCP сервер
    $server = new \Bitrix24\Mcp\Server();

    // Регистрируем инструменты
    $server->registerTool(new \Bitrix24\Mcp\Tools\DatabaseSchema());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmSchema());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmEntityList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmEntityGet());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmActivityList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmTimelineList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ModulesList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CodeSearch());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ClassInspector());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ComponentsList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\EventHandlers());
    $server->registerTool(new \Bitrix24\Mcp\Tools\FileReader());
    $server->registerTool(new \Bitrix24\Mcp\Tools\SqlQuery());

    // Регистрируем ресурсы
    $server->registerResource(new \Bitrix24\Mcp\Resources\ProjectStructure());
    $server->registerResource(new \Bitrix24\Mcp\Resources\D7Documentation());
    $server->registerResource(new \Bitrix24\Mcp\Resources\CodingStandards());

    // Запускаем сервер с stdio транспортом
    $transport = new \Bitrix24\Mcp\Transport\StdioTransport();
    $server->run($transport);
} catch (\Exception $e) {
    fwrite(STDERR, "MCP Server Error: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
