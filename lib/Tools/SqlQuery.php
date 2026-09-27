<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Application;

/**
 * Инструмент для выполнения read-oriented SELECT запросов без явных DML-операций.
 *
 * ВАЖНО: это НЕ полноценная SQL-песочница. Без специальных DB-пользователей
 * (read-only, без SUPER/FILE) невозможно полностью исключить все side-effect
 * паттерны на уровне PHP. Код выполняет жёсткую статическую фильтрацию
 * и ограничивает bounds, но DB-level enforcement остаётся обязанностью
 * администратора (отдельный read-only DB user, grants без SUPER/FILE).
 */
class SqlQuery extends AbstractTool
{
    /* ------------------------------------------------------------------ */
    /*  Константы                                                         */
    /* ------------------------------------------------------------------ */
    private const MAX_ROWS = 200;               // жёсткий bound
    private const MAX_QUERY_LENGTH = 5000;      // жёсткий bound
    private const MAX_LIMIT = 200;              // жёсткий bound на LIMIT
    private const MAX_EXECUTION_TIME = 30;      // секунд
    private const MAX_MEMORY_PER_ROW = 102400;  // ~100 KB / строка

    /* ------------------------------------------------------------------ */
    /*  Безопасный default / feature flag                                 */
    /* ------------------------------------------------------------------ */
    private const READ_ONLY_ENFORCED = false;   // true => полный отказ;
                                               // false => фильтрация + warning.
                                               // Без DB-level read-only user
                                               // полное enforcement невозможно.

    /* ------------------------------------------------------------------ */
    /*  Запрещённые ключевые слова (DML, DDL, dangerous functions)        */
    /* ------------------------------------------------------------------ */
    private const FORBIDDEN_KEYWORDS = [
        // Variables / dangerous functions / expressions
        'SET', 'VARIABLE', 'VARIABLES', '@', 'LOAD_FILE', 'INTO', 'INFORMATION_SCHEMA',
        'SYS', 'PERFORMANCE_SCHEMA', 'PROCEDURE', 'TRIGGER', 'EVENT', 'PREPARE',
        'DEALLOCATE', 'DO', 'COMMIT', 'ROLLBACK', 'START', 'TRANSACTION', 'SAVEPOINT',
        // DML / DDL / destructive
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE',
        'REPLACE', 'RENAME', 'GRANT', 'REVOKE', 'MERGE', 'CALL', 'EXEC', 'EXECUTE',
        // File / load
        'LOAD', 'OUTFILE', 'DUMPFILE', 'FILE',
        // Locking / blocking
        'FOR', 'LOCK', 'LOCKED', 'UNLOCK', 'TABLES', 'GET_LOCK', 'RELEASE_LOCK',
        // Side-effect / benchmark
        'SLEEP', 'BENCHMARK',
    ];

    /* ------------------------------------------------------------------ */
    /*  Запрещённые паттерны (регулярные выражения)                      */
    /* ------------------------------------------------------------------ */
    private const FORBIDDEN_PATTERNS = [
        // Lock constructs
        '/\bFOR\s+UPDATE\b/i',
        '/\bLOCK\s+IN\s+SHARE\s+MODE\b/i',
        '/\bGET_LOCK\b/i',
        '/\bRELEASE_LOCK\b/i',
        // File / load
        '/\bINTO\s+OUTFILE\b/i',
        '/\bINTO\s+DUMPFILE\b/i',
        '/\bSELECT\s+.*\s+INTO\s+@/i',
        '/\bLOAD_FILE\b/i',
        // Session / variables / user-defined
        '/\bSET\s+SESSION\b/i',
        '/\bSET\s+GLOBAL\b/i',
        '/\bSET\s+LOCAL\b/i',
        '/\bUSER\s*\(/i',
        '/\bSESSION_USER\b/i',
        '/\bCURRENT_USER\b/i',
        '/\bSYSTEM_USER\b/i',
        '/\bDATABASE\s*\(/i',
        // Dangerous expressions
        '/\bSLEEP\s*\(/i',
        '/\bBENCHMARK\s*\(/i',
        // Multi-statement after stripping strings (hardening)
        '/;\s*(SELECT|INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|' .
        'TRUNCATE|REPLACE|RENAME|GRANT|REVOKE|SET|DO|CALL|' .
        'EXEC|EXECUTE)\b/i',
        // SQL comments (anywhere - hardening)
        '/(--|#|\/\*|\*\/)/',
        // Dangerous schema inspection
        '/\bINFORMATION_SCHEMA\b/i',
        '/\bPERFORMANCE_SCHEMA\b/i',
        '/\bMYSQL\b/i',
    ];

    /* ------------------------------------------------------------------ */
    /*  API                                                               */
    /* ------------------------------------------------------------------ */
    public function getName(): string
    {
        return 'sql_query';
    }

    public function getDescription(): string
    {
        return 'Read-oriented SELECT запросы с жёсткими bounds и фильтрацией '
            . 'опасных паттернов. НЕ полноценная SQL-песочница: без '
            . 'отдельного DB-user с правами read-only невозможно '
            . 'полностью исключить все side-effects. '
            . (self::READ_ONLY_ENFORCED ? 'READ-ONLY ENFORCED ON.' : 'READ-ONLY ENFORCED OFF - feature flag default.');
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Read-oriented SQL SELECT запрос (без DML/DDL/side-effect паттернов)',
                    'maxLength' => self::MAX_QUERY_LENGTH,
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Максимум строк (default=' . self::MAX_LIMIT . ', max=' . self::MAX_LIMIT . ')',
                    'default' => self::MAX_LIMIT,
                    'maximum' => self::MAX_LIMIT,
                    'minimum' => 1,
                ],
            ],
            'required' => ['query'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Execute                                                           */
    /* ------------------------------------------------------------------ */
    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['query']);

        $query = trim((string)($arguments['query'] ?? ''));

        // Жёсткие bounds на длину запроса
        if (strlen($query) === 0) {
            throw new \InvalidArgumentException('Query is empty');
        }
        if (strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new \InvalidArgumentException(
                'Query exceeds maximum length (' . self::MAX_QUERY_LENGTH . ' chars)'
            );
        }

        // Жёсткие bounds на limit
        $limit = (int)($this->getParam($arguments, 'limit', self::MAX_LIMIT));
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException(
                'Limit must be between 1 and ' . self::MAX_LIMIT
            );
        }

        // Валидация запроса
        $this->validateQuery($query);

        // Feature flag: полный отказ, если read-only enforcement включён
        if (self::READ_ONLY_ENFORCED) {
            throw new \RuntimeException(
                'Read-only enforcement enabled but DB-level read-only user is not available. '
                . 'Full read-only enforcement requires a dedicated DB user with SELECT-only grants. '
                . 'This is a documented limitation of this tool.'
            );
        }

        // Принудительно добавляем / заменяем LIMIT
        $query = $this->normalizeLimit($query, $limit);

        $connection = Application::getConnection();

        try {
            // Устанавливаем таймаут на уровне MySQL (defence in depth)
            $connection->query(
                'SET SESSION MAX_EXECUTION_TIME = ' . (self::MAX_EXECUTION_TIME * 1000)
            );

            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $result = $connection->query($query);

            $rows = [];
            $columns = [];
            $rowCount = 0;
            $truncated = false;

            while ($row = $result->fetch()) {
                // Таймаут
                if ((microtime(true) - $startTime) > self::MAX_EXECUTION_TIME) {
                    $truncated = true;
                    break;
                }

                // Память (оценка)
                $currentMemory = memory_get_usage(true);
                if (($currentMemory - $startMemory) > ($rowCount * self::MAX_MEMORY_PER_ROW)) {
                    $truncated = true;
                    break;
                }

                if (empty($columns)) {
                    $columns = array_keys($row);
                }

                $rows[] = $row;
                $rowCount++;

                if ($rowCount >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }
            }

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return [
                'query' => $query,
                'columns' => $columns,
                'rows' => $rows,
                'affected_rows' => count($rows),
                'execution_time_ms' => $executionTime,
                'truncated' => $truncated,
                'success' => true,
                'warning' => 'SQL query executed without DB-level read-only enforcement. '
                    . 'Full read-only safety requires a dedicated DB user (SELECT-only grants).',
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Query execution failed: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Limit нормализация                                                */
    /* ------------------------------------------------------------------ */
    private function normalizeLimit(string $query, int $maxLimit): string
    {
        $query = rtrim($query, '; ');

        // Удаляем существующий LIMIT, если он превышает max или просто заменяем
        $query = preg_replace('/\s+LIMIT\s+\d+\s*(,\s*\d+)?/i', '', $query);
        $query = preg_replace('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+/i', '', $query);

        // Добавляем безопасный LIMIT
        $query .= ' LIMIT ' . $maxLimit;
        return $query;
    }

    /* ------------------------------------------------------------------ */
    /*  Валидация SQL запроса                                              */
    /* ------------------------------------------------------------------ */
    private function validateQuery(string $query): void
    {
        // 1) Только SELECT в начале (без пробелов перед SELECT не разрешаем ничего)
        if (!preg_match('/^\s*SELECT\s+(DISTINCT\s+)?/i', $query)) {
            throw new \InvalidArgumentException(
                'Only SELECT queries are allowed (must start with SELECT)'
            );
        }

        // 2) Запрещённые ключевые слова (word boundaries)
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
            if (preg_match($pattern, $query)) {
                throw new \InvalidArgumentException(
                    'Forbidden keyword/pattern detected: ' . $keyword
                );
            }
        }

        // 3) Запрещённые паттерны (регулярные выражения)
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $query)) {
                throw new \InvalidArgumentException(
                    'Forbidden SQL pattern detected: ' . $pattern
                );
            }
        }

        // 4) Комментарии запрещены полностью (жёсткое ограничение)
        if (preg_match('/(--|#|\/\*|\*\/)/', $query)) {
            throw new \InvalidArgumentException(
                'SQL comments are not allowed (potential side-effect / injection vector)'
            );
        }

        // 5) Множественные операторы (после удаления строковых литералов)
        $clean = $this->stripStringLiterals($query);
        $statements = array_filter(array_map('trim', explode(';', $clean)));
        if (count($statements) > 1) {
            throw new \InvalidArgumentException(
                'Multiple SQL statements are not allowed (potential side-effect / injection vector)'
            );
        }

        // 6) UNION проверка частей
        if (preg_match('/\bUNION\s+(ALL\s+)?SELECT\b/i', $query)) {
            $parts = preg_split('/\bUNION\s+(ALL\s+)?SELECT\b/i', $query);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                // Каждая часть UNION тоже должна быть SELECT
                if (!preg_match('/^\s*SELECT\s+/i', $part)) {
                    throw new \InvalidArgumentException(
                        'UNION part does not start with SELECT'
                    );
                }
                foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
                    $pattern = '/\b' . preg_quote($keyword, '/') . '\b/i';
                    if (preg_match($pattern, $part)) {
                        throw new \InvalidArgumentException(
                            'Forbidden keyword in UNION part: ' . $keyword
                        );
                    }
                }
                foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                    if (preg_match($pattern, $part)) {
                        throw new \InvalidArgumentException(
                            'Forbidden SQL pattern in UNION part: ' . $pattern
                        );
                    }
                }
            }
        }

        // 7) Запрещённые функции в выражениях
        $dangerousFunctions = [
            'BENCHMARK', 'SLEEP', 'LOAD_FILE', 'USER', 'SESSION_USER',
            'CURRENT_USER', 'SYSTEM_USER', 'DATABASE', 'VERSION',
            'SCHEMA', 'FOUND_ROWS', 'LAST_INSERT_ID',
        ];
        foreach ($dangerousFunctions as $func) {
            if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $query)) {
                throw new \InvalidArgumentException(
                    'Dangerous function call detected: ' . $func . '()'
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Утилита: удаление строковых литералов                              */
    /* ------------------------------------------------------------------ */
    private function stripStringLiterals(string $query): string
    {
        // Простые кавычки
        $clean = preg_replace("/'[^']*'/", "''", $query);
        // Двойные кавычки
        $clean = preg_replace('/"[^"]*"/', '""', $clean);
        // Backtick-имена
        $clean = preg_replace('/`[^`]*`/', '``', $clean);
        return $clean ?? $query;
    }
}
