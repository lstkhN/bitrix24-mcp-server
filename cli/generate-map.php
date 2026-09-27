#!/usr/bin/env php
<?php

/**
 * Генератор CLAUDE.md - карты проекта для Cursor
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
    echo "\n❌ ОШИБКА: Модуль '$moduleId' не установлен.\n";
    echo "Установите модуль через админку Битрикс сначала.\n\n";
    exit(1);
}

\Bitrix\Main\Loader::includeModule($moduleId);

$renderTree = static function (array $tree, int $level = 0, string $prefix = '') use (&$renderTree): string {
    $output = '';
    $items = array_keys($tree);
    $count = count($items);

    foreach ($items as $index => $item) {
        $isLast = ($index === $count - 1);

        $output .= $prefix;
        $output .= $isLast ? '└── ' : '├── ';
        $output .= $item . "/\n";

        if (is_array($tree[$item]) && !empty($tree[$item])) {
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $output .= $renderTree($tree[$item], $level + 1, $newPrefix);
        }
    }

    return $output;
};

echo "Генерация CLAUDE.md...\n";

// Создаём ресурс
$resource = new \Bitrix24\Mcp\Resources\ProjectStructure();

// Получаем структуру проекта
$structureJson = $resource->read();
$structure = json_decode($structureJson, true);

// Формируем markdown
$markdown = <<<'HEADER'
# Битрикс24 Project Structure

Эта карта проекта автоматически сгенерирована MCP сервером (bitrix24.mcp).

HEADER;

$markdown .= "\n**Дата генерации**: " . date('Y-m-d H:i:s') . "\n\n";

$markdown .= "---\n\n";

// 1. Установленные модули
$markdown .= "## 📦 Установленные модули\n\n";

if (isset($structure['modules']) && !empty($structure['modules'])) {
    foreach ($structure['modules'] as $module) {
        $markdown .= "- `{$module['id']}`\n";
    }
} else {
    $markdown .= "*Нет информации о модулях*\n";
}

$markdown .= "\n";

// 2. Структура /local/
$markdown .= "## 🏗️  Структура /local/\n\n";
$markdown .= "```\n";
$markdown .= "/local/\n";

if (isset($structure['custom_code']['paths']['local'])) {
    $markdown .= $renderTree($structure['custom_code']['paths']['local'], 1);
}

$markdown .= "```\n\n";

// 3. Компоненты
$markdown .= "## 🧩 Компоненты\n\n";

// Битрикс компоненты
if (isset($structure['components']['bitrix']) && !empty($structure['components']['bitrix'])) {
    $markdown .= "### Битрикс компоненты (избранные):\n\n";

    $count = 0;
    foreach ($structure['components']['bitrix'] as $component) {
        $markdown .= "- `bitrix:$component`\n";
        $count++;

        if ($count >= 20) {
            $markdown .= "- ... и ещё " . (count($structure['components']['bitrix']) - 20) . " компонентов\n";
            break;
        }
    }

    $markdown .= "\n";
}

// Локальные компоненты
if (isset($structure['components']['local']) && !empty($structure['components']['local'])) {
    $markdown .= "### Локальные компоненты:\n\n";

    foreach ($structure['components']['local'] as $vendor => $components) {
        if (!empty($components)) {
            $markdown .= "**$vendor:**\n\n";

            foreach ($components as $component) {
                $markdown .= "- `$vendor:$component`\n";
            }

            $markdown .= "\n";
        }
    }
}

// 4. CRM Entities
$markdown .= "## 👥 CRM Entities\n\n";
$markdown .= "| Сущность | Класс | Таблица |\n";
$markdown .= "|----------|-------|----------|\n";
$markdown .= "| Лид | `\\Bitrix\\Crm\\LeadTable` | `b_crm_lead` |\n";
$markdown .= "| Сделка | `\\Bitrix\\Crm\\DealTable` | `b_crm_deal` |\n";
$markdown .= "| Контакт | `\\Bitrix\\Crm\\ContactTable` | `b_crm_contact` |\n";
$markdown .= "| Компания | `\\Bitrix\\Crm\\CompanyTable` | `b_crm_company` |\n";
$markdown .= "| Предложение | `\\Bitrix\\Crm\\QuoteTable` | `b_crm_quote` |\n\n";

// 5. Полезные команды
$markdown .= "## 💻 Полезные команды MCP\n\n";
$markdown .= "Используйте эти команды через AI-ассистента:\n\n";
$markdown .= "- **Схема БД**: `Покажи структуру таблицы b_crm_lead`\n";
$markdown .= "- **CRM поля**: `Какие поля у сущности Lead в CRM?`\n";
$markdown .= "- **Поиск кода**: `Найди все обработчики OnAfterCrmDealAdd`\n";
$markdown .= "- **Анализ класса**: `Покажи методы класса \\Bitrix\\Crm\\LeadTable`\n";
$markdown .= "- **SQL запрос**: `SELECT ID, TITLE FROM b_crm_lead LIMIT 10`\n";
$markdown .= "- **Чтение файла**: `Прочитай /local/php_interface/init.php`\n\n";

// 6. Best Practices
$markdown .= "## ✨ Best Practices\n\n";
$markdown .= "### D7 ORM\n\n";
$markdown .= "```php\n";
$markdown .= "// Получение списка\n";
$markdown .= "\$result = \\Bitrix\\Crm\\LeadTable::getList([\n";
$markdown .= "    'select' => ['ID', 'TITLE', 'NAME'],\n";
$markdown .= "    'filter' => ['STATUS_ID' => 'NEW'],\n";
$markdown .= "    'order' => ['ID' => 'DESC'],\n";
$markdown .= "    'limit' => 10,\n";
$markdown .= "]);\n";
$markdown .= "```\n\n";

$markdown .= "### События\n\n";
$markdown .= "```php\n";
$markdown .= "use Bitrix\\Main\\EventManager;\n\n";
$markdown .= "\$eventManager = EventManager::getInstance();\n";
$markdown .= "\$eventManager->addEventHandler(\n";
$markdown .= "    'crm',\n";
$markdown .= "    'OnAfterCrmLeadAdd',\n";
$markdown .= "    ['\\Local\\Handlers\\LeadHandler', 'onAfterAdd']\n";
$markdown .= ");\n";
$markdown .= "```\n\n";

// 7. Footer
$markdown .= "---\n\n";
$markdown .= "*Эта карта проекта автоматически сгенерирована. ";
$markdown .= "Для обновления выполните: `php cli/generate-map.php`*\n";

// Сохраняем файл
$outputPath = $_SERVER['DOCUMENT_ROOT'] . '/local/CLAUDE.md';
file_put_contents($outputPath, $markdown);

echo "✅ CLAUDE.md успешно сгенерирован: $outputPath\n";
