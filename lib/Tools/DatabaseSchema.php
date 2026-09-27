<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Application;

/**
 * Инструмент для получения схемы таблиц базы данных
 */
class DatabaseSchema extends AbstractTool
{
    public function getName(): string
    {
        return 'database_schema';
    }

    public function getDescription(): string
    {
        return 'Получение схемы таблиц базы данных MySQL из INFORMATION_SCHEMA. '
            . 'Поддерживает фильтрацию по имени таблицы или паттерну.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => [
                    'type' => 'string',
                    'description' => 'Имя конкретной таблицы (опционально)',
                ],
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Паттерн для поиска таблиц, например "b_crm_*" (опционально)',
                ],
                'include_columns' => [
                    'type' => 'boolean',
                    'description' => 'Включать описание колонок (по умолчанию true)',
                    'default' => true,
                ],
            ],
        ];
    }

    public function execute(array $arguments): array
    {
        $table = $this->getParam($arguments, 'table');
        $pattern = $this->getParam($arguments, 'pattern');
        $includeColumns = $this->getParam($arguments, 'include_columns', true);

        $connection = Application::getConnection();
        $database = $connection->getDatabase();

        // Получаем список таблиц
        $tables = $this->getTables($connection, $database, $table, $pattern);

        $result = [];

        foreach ($tables as $tableName) {
            $tableInfo = [
                'name' => $tableName,
            ];

            if ($includeColumns) {
                $tableInfo['columns'] = $this->getColumns($connection, $database, $tableName);
                $tableInfo['indexes'] = $this->getIndexes($connection, $database, $tableName);
            }

            $result[] = $tableInfo;
        }

        return [
            'database' => $database,
            'tables' => $result,
            'total' => count($result),
        ];
    }

    /**
     * Получение списка таблиц
     */
    private function getTables($connection, string $database, ?string $table, ?string $pattern): array
    {
        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ?";
        $params = [$database];

        if ($table) {
            $sql .= " AND TABLE_NAME = ?";
            $params[] = $table;
        } elseif ($pattern) {
            // Преобразуем паттерн: b_crm_* -> b_crm_%
            $likePattern = str_replace('*', '%', $pattern);
            $sql .= " AND TABLE_NAME LIKE ?";
            $params[] = $likePattern;
        }

        $sql .= " ORDER BY TABLE_NAME";

        $result = $connection->query($sql, $params);
        $tables = [];

        while ($row = $result->fetch()) {
            $tables[] = $row['TABLE_NAME'];
        }

        return $tables;
    }

    /**
     * Получение колонок таблицы
     */
    private function getColumns($connection, string $database, string $table): array
    {
        $sql = "SELECT
                    COLUMN_NAME as name,
                    COLUMN_TYPE as type,
                    IS_NULLABLE as nullable,
                    COLUMN_KEY as `key`,
                    COLUMN_DEFAULT as `default`,
                    EXTRA as extra,
                    COLUMN_COMMENT as comment
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION";

        $result = $connection->query($sql, [$database, $table]);
        $columns = [];

        while ($row = $result->fetch()) {
            $columns[] = [
                'name' => $row['name'],
                'type' => $row['type'],
                'nullable' => $row['nullable'] === 'YES',
                'key' => $row['key'] ?: null,
                'default' => $row['default'],
                'extra' => $row['extra'] ?: null,
                'comment' => $row['comment'] ?: null,
            ];
        }

        return $columns;
    }

    /**
     * Получение индексов таблицы
     */
    private function getIndexes($connection, string $database, string $table): array
    {
        $sql = "SELECT
                    INDEX_NAME as name,
                    COLUMN_NAME as column_name,
                    NON_UNIQUE as non_unique,
                    SEQ_IN_INDEX as seq
                FROM INFORMATION_SCHEMA.STATISTICS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY INDEX_NAME, SEQ_IN_INDEX";

        $result = $connection->query($sql, [$database, $table]);
        $indexes = [];

        while ($row = $result->fetch()) {
            $indexName = $row['name'];

            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'name' => $indexName,
                    'unique' => $row['non_unique'] == 0,
                    'columns' => [],
                ];
            }

            $indexes[$indexName]['columns'][] = $row['column_name'];
        }

        return array_values($indexes);
    }
}
