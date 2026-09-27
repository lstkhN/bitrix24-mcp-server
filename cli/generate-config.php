#!/usr/bin/env php
<?php

/**
 * Генератор конфигурационных файлов для AI-ассистентов
 * Работает ДО и ПОСЛЕ установки модуля
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
$moduleInstalled = \Bitrix\Main\ModuleManager::isModuleInstalled($moduleId);

if (!$moduleInstalled) {
    echo "\n";
    echo "⚠️  ВНИМАНИЕ: Модуль '$moduleId' ещё не установлен через админку Битрикс.\n";
    echo "Для установки перейдите: " . $_SERVER['DOCUMENT_ROOT'] . "/bitrix/admin/partner_modules.php\n";
    echo "Найдите 'MCP Server для Битрикс24' и нажмите 'Установить'\n\n";
    echo "Сгенерирую токен вручную для первичной настройки...\n\n";

    // Генерируем токен вручную
    $token = bin2hex(random_bytes(32));

    // Пытаемся сохранить токен
    try {
        \Bitrix\Main\Config\Option::set($moduleId, 'auth_token', $token);
        echo "✅ Токен сохранён в настройки Битрикс\n\n";
    } catch (\Exception $e) {
        echo "⚠️  Не удалось сохранить токен в базу: " . $e->getMessage() . "\n";
        echo "После установки модуля запустите этот скрипт снова\n\n";
    }
} else {
    // Модуль установлен, работаем штатно
    \Bitrix\Main\Loader::includeModule($moduleId);

    // Получаем токен из настроек или генерируем новый
    $token = \Bitrix\Main\Config\Option::get($moduleId, 'auth_token', '');

    if (empty($token)) {
        $token = bin2hex(random_bytes(32));
        \Bitrix\Main\Config\Option::set($moduleId, 'auth_token', $token);
        echo "✅ Новый токен сгенерирован и сохранён\n\n";
    } else {
        echo "✅ Модуль установлен, используется существующий токен\n\n";
    }
}

if (empty($token)) {
    echo "❌ Не удалось получить токен авторизации.\n";
    exit(1);
}

// Определяем пути
$modulePath = $_SERVER['DOCUMENT_ROOT'] . '/local/modules/bitrix24.mcp';
$projectRoot = $_SERVER['DOCUMENT_ROOT'];

echo "=" . str_repeat("=", 78) . "=\n";
echo "  Генератор конфигурации MCP сервера для Битрикс24\n";
echo "=" . str_repeat("=", 78) . "=\n\n";

// 1. .mcp.json для Claude Code (SSH)
$mcpJsonSsh = [
    'mcpServers' => [
        'bitrix' => [
            'command' => 'ssh',
            'args' => [
                'user@your-server.com',
                "php $modulePath/cli/server.php",
            ],
        ],
    ],
];

echo "📄 .mcp.json (Claude Code - SSH вариант):\n";
echo str_repeat("-", 80) . "\n";
echo json_encode($mcpJsonSsh, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo str_repeat("-", 80) . "\n\n";

// 2. .mcp.json для Claude Code (HTTP)
$mcpJsonHttp = [
    'mcpServers' => [
        'bitrix' => [
            'command' => 'npx',
            'args' => [
                '-y',
                '@modelcontextprotocol/sdk',
                'http',
                'https://your-domain.com/api/mcp/',
                '--header',
                "Authorization: Bearer $token",
            ],
        ],
    ],
];

echo "📄 .mcp.json (Claude Code - HTTP вариант):\n";
echo str_repeat("-", 80) . "\n";
echo json_encode($mcpJsonHttp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo str_repeat("-", 80) . "\n\n";

// 3. .cursorrules для Cursor
$cursorrules = <<<'RULES'
# Битрикс24 Development Rules

## D7 ORM
- Используй \Bitrix\Main\ORM\Data\DataManager для работы с БД
- Всегда используй getList() вместо прямых SQL запросов
- Пример: \Bitrix\Crm\LeadTable::getList([...])

## CRM Entities
- Lead: \Bitrix\Crm\LeadTable (Лиды)
- Deal: \Bitrix\Crm\DealTable (Сделки)
- Contact: \Bitrix\Crm\ContactTable (Контакты)
- Company: \Bitrix\Crm\CompanyTable (Компании)

## События
- Регистрация через EventManager::getInstance()->addEventHandler()
- Обработчики в классах, не в functions
- Используй namespace для классов обработчиков

## Компоненты
- Структура: component.php или class.php
- Параметры описываются в .parameters.php
- Шаблоны в templates/.default/

## Стандарты кодирования
- PSR-12
- Namespace: \Local\YourModule\
- Autoload через composer

## Безопасность
- Всегда экранируй вывод: htmlspecialcharsbx()
- Используй prepared statements для SQL
- Проверяй bitrix_sessid() для форм

## Производительность
- Используй кэширование (\Bitrix\Main\Data\Cache)
- Всегда указывай limit в запросах
- Выбирай только нужные поля в select

RULES;

echo "📄 .cursorrules (Cursor):\n";
echo str_repeat("-", 80) . "\n";
echo $cursorrules;
echo str_repeat("-", 80) . "\n\n";

// 4. CLAUDE.md для Cursor
echo "📄 CLAUDE.md (Cursor - карта проекта):\n";
echo str_repeat("-", 80) . "\n";
echo "Для генерации CLAUDE.md используйте:\n";
echo "php $modulePath/cli/generate-map.php\n";
echo str_repeat("-", 80) . "\n\n";

// 5. Токен авторизации
echo "🔑 Токен авторизации (для HTTP):\n";
echo str_repeat("-", 80) . "\n";
echo "$token\n";
echo str_repeat("-", 80) . "\n\n";

// 6. Инструкции
echo "📋 Инструкции по установке:\n\n";

echo "1️⃣  Для Claude Code (SSH):\n";
echo "   - Создайте .mcp.json в корне вашего проекта\n";
echo "   - Замените 'user@your-server.com' на ваш SSH хост\n";
echo "   - Перезапустите Claude Code\n\n";

echo "2️⃣  Для Claude Code (HTTP):\n";
echo "   - Создайте .mcp.json в корне вашего проекта\n";
echo "   - Замените 'https://your-domain.com' на ваш домен\n";
echo "   - Настройте роут /api/mcp/ в Битрикс24\n";
echo "   - Перезапустите Claude Code\n\n";

echo "3️⃣  Для Cursor:\n";
echo "   - Создайте .cursorrules в корне проекта\n";
echo "   - Создайте CLAUDE.md (или сгенерируйте через generate-map.php)\n";
echo "   - Перезапустите Cursor\n\n";

echo "✅ Конфигурация сгенерирована!\n\n";

// Сохраняем файлы
$outputDir = $projectRoot . '/local';

if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// Сохраняем .mcp.json (HTTP вариант)
file_put_contents(
    $outputDir . '/.mcp.json.example',
    json_encode($mcpJsonHttp, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

// Сохраняем .cursorrules
file_put_contents(
    $outputDir . '/.cursorrules.example',
    $cursorrules
);

echo "📁 Примеры файлов сохранены в /local/:\n";
echo "   - .mcp.json.example\n";
echo "   - .cursorrules.example\n\n";

echo "💡 Переименуйте файлы, удалив .example, и настройте по вашим нуждам.\n\n";
