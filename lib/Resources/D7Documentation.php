<?php

namespace Bitrix24\Mcp\Resources;

use ReflectionClass;

/**
 * Ресурс с документацией D7 ORM классов
 */
class D7Documentation extends AbstractResource
{
    public function getUri(): string
    {
        return 'bitrix://docs/d7/{class}';
    }

    public function getName(): string
    {
        return 'D7 Documentation';
    }

    public function getDescription(): string
    {
        return 'Документация по D7 ORM классам Битрикс24 с примерами использования.';
    }

    public function getMimeType(): string
    {
        return 'text/markdown';
    }

    public function read(array $params = []): string
    {
        $className = $params['class'] ?? '';

        if (empty($className)) {
            return $this->getGeneralDocumentation();
        }

        return $this->getClassDocumentation($className);
    }

    /**
     * Общая документация по D7
     */
    private function getGeneralDocumentation(): string
    {
        return <<<'MARKDOWN'
# Документация D7 ORM Битрикс24

## Основные классы

### CRM
- `\Bitrix\Crm\LeadTable` - Лиды
- `\Bitrix\Crm\DealTable` - Сделки
- `\Bitrix\Crm\ContactTable` - Контакты
- `\Bitrix\Crm\CompanyTable` - Компании
- `\Bitrix\Crm\QuoteTable` - Предложения

### Main
- `\Bitrix\Main\UserTable` - Пользователи
- `\Bitrix\Main\GroupTable` - Группы пользователей

## Примеры использования

### Получение списка
```php
$result = \Bitrix\Crm\LeadTable::getList([
    'select' => ['ID', 'TITLE', 'NAME', 'LAST_NAME'],
    'filter' => ['STATUS_ID' => 'NEW'],
    'order' => ['ID' => 'DESC'],
    'limit' => 10,
]);

while ($lead = $result->fetch()) {
    echo $lead['TITLE'] . "\n";
}
```

### Получение одного элемента
```php
$lead = \Bitrix\Crm\LeadTable::getByPrimary(123, [
    'select' => ['*'],
])->fetch();
```

### Добавление
```php
$result = \Bitrix\Crm\LeadTable::add([
    'TITLE' => 'Новый лид',
    'NAME' => 'Иван',
    'LAST_NAME' => 'Иванов',
    'STATUS_ID' => 'NEW',
]);

if ($result->isSuccess()) {
    $leadId = $result->getId();
}
```

### Обновление
```php
$result = \Bitrix\Crm\LeadTable::update(123, [
    'TITLE' => 'Обновлённый лид',
    'STATUS_ID' => 'IN_PROCESS',
]);
```

### Удаление
```php
$result = \Bitrix\Crm\LeadTable::delete(123);
```

## Полезные методы

- `getMap()` - Получить карту полей
- `getTableName()` - Получить имя таблицы
- `getEntity()` - Получить сущность

MARKDOWN;
    }

    /**
     * Документация по конкретному классу
     */
    private function getClassDocumentation(string $className): string
    {
        $className = ltrim($className, '\\');

        if (!class_exists($className)) {
            return "# Ошибка\n\nКласс не найден: $className";
        }

        try {
            $reflection = new ReflectionClass($className);

            $markdown = "# $className\n\n";

            // Описание из PHPDoc
            if ($reflection->getDocComment()) {
                $markdown .= $this->cleanDocComment($reflection->getDocComment()) . "\n\n";
            }

            // Родительский класс
            if ($reflection->getParentClass()) {
                $markdown .= "**Родительский класс**: `" . $reflection->getParentClass()->getName() . "`\n\n";
            }

            // Путь к файлу
            if ($reflection->getFileName()) {
                $fileName = str_replace($_SERVER['DOCUMENT_ROOT'], '', $reflection->getFileName());
                $markdown .= "**Файл**: `$fileName`\n\n";
            }

            // Если это D7 ORM Table класс
            if (is_subclass_of($className, '\Bitrix\Main\ORM\Data\DataManager')) {
                $markdown .= $this->getTableClassDocumentation($className, $reflection);
            }

            // Публичные методы
            $markdown .= "## Публичные методы\n\n";

            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                if ($method->getDeclaringClass()->getName() === $className) {
                    $markdown .= $this->getMethodDocumentation($method);
                }
            }

            return $markdown;
        } catch (\Exception $e) {
            return "# Ошибка\n\n" . $e->getMessage();
        }
    }

    /**
     * Документация для D7 ORM Table классов
     */
    private function getTableClassDocumentation(string $className, ReflectionClass $reflection): string
    {
        $markdown = "## D7 ORM Table\n\n";

        // Имя таблицы
        try {
            $tableName = $className::getTableName();
            $markdown .= "**Таблица БД**: `$tableName`\n\n";
        } catch (\Exception $e) {
            // Игнорируем
        }

        // Карта полей
        try {
            $map = $className::getMap();

            $markdown .= "### Поля\n\n";
            $markdown .= "| Поле | Тип |\n";
            $markdown .= "|------|-----|\n";

            foreach ($map as $fieldName => $field) {
                $type = get_class($field);
                $type = substr($type, strrpos($type, '\\') + 1);
                $markdown .= "| `$fieldName` | $type |\n";
            }

            $markdown .= "\n";
        } catch (\Exception $e) {
            // Игнорируем
        }

        $markdown .= "### Пример использования\n\n";
        $markdown .= "```php\n";
        $markdown .= "// Получение списка\n";
        $markdown .= "\$result = \\$className::getList([\n";
        $markdown .= "    'select' => ['*'],\n";
        $markdown .= "    'filter' => [],\n";
        $markdown .= "    'order' => ['ID' => 'DESC'],\n";
        $markdown .= "    'limit' => 10,\n";
        $markdown .= "]);\n\n";
        $markdown .= "while (\$item = \$result->fetch()) {\n";
        $markdown .= "    // ...\n";
        $markdown .= "}\n";
        $markdown .= "```\n\n";

        return $markdown;
    }

    /**
     * Документация метода
     */
    private function getMethodDocumentation(\ReflectionMethod $method): string
    {
        $markdown = "### `" . $method->getName() . "()`\n\n";

        if ($method->getDocComment()) {
            $markdown .= $this->cleanDocComment($method->getDocComment()) . "\n\n";
        }

        // Параметры
        $params = $method->getParameters();

        if (!empty($params)) {
            $markdown .= "**Параметры**:\n";

            foreach ($params as $param) {
                $paramType = $param->hasType() ? $param->getType()->getName() : 'mixed';
                $markdown .= "- `$paramType \$" . $param->getName() . "`";

                if ($param->isOptional()) {
                    $markdown .= " (опционально)";
                }

                $markdown .= "\n";
            }

            $markdown .= "\n";
        }

        // Возвращаемое значение
        if ($method->hasReturnType()) {
            $returnType = $method->getReturnType()->getName();
            $markdown .= "**Возвращает**: `$returnType`\n\n";
        }

        return $markdown;
    }

    /**
     * Очистка PHPDoc комментария
     */
    private function cleanDocComment(string $docComment): string
    {
        $docComment = preg_replace('/^\/\*\*\s*/', '', $docComment);
        $docComment = preg_replace('/\s*\*\/$/', '', $docComment);

        $lines = explode("\n", $docComment);
        $lines = array_map(function ($line) {
            return preg_replace('/^\s*\*\s?/', '', $line);
        }, $lines);

        return trim(implode("\n", $lines));
    }
}
