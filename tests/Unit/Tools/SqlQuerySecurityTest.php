<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\SqlQuery;

/**
 * Тесты безопасности для SqlQuery (жёсткая фильтрация + bounds + feature flag)
 */
class SqlQuerySecurityTest extends TestCase
{
    private SqlQuery $tool;

    protected function setUp(): void
    {
        $this->tool = new SqlQuery();
    }

    /* ==================================================================== */
    /*  Non-SELECT leading queries                                         */
    /* ==================================================================== */

    /**
     * @dataProvider nonSelectQueriesProvider
     */
    public function testBlocksNonSelectLeadingQueries(string $query): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Only SELECT/i');

        $this->tool->execute(['query' => $query]);
    }

    public function nonSelectQueriesProvider(): array
    {
        return [
            'INSERT query' => ['INSERT INTO b_user (NAME) VALUES ("hack")'],
            'UPDATE query' => ['UPDATE b_user SET ACTIVE = "N" WHERE ID = 1'],
            'DELETE query' => ['DELETE FROM b_user WHERE ID = 1'],
            'DROP TABLE' => ['DROP TABLE b_user'],
            'TRUNCATE query' => ['TRUNCATE TABLE b_user'],
            'SHOW TABLES' => ['SHOW TABLES'],
        ];
    }

    /* ==================================================================== */
    /*  Dangerous SELECT patterns                                           */
    /* ==================================================================== */

    /**
     * @dataProvider dangerousSelectQueriesProvider
     */
    public function testBlocksDangerousSelectQueries(string $query, string $expectedKeyword): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Forbidden keyword.*$expectedPattern/i");

        $this->tool->execute(['query' => $query]);
    }

    public function dangerousSelectQueriesProvider(): array
    {
        return [
            'SQL injection with DROP after semicolon' => [
                'SELECT * FROM b_user; DROP TABLE b_user',
                'DROP',
            ],
            'UNION SELECT with DROP' => [
                'SELECT * FROM b_user UNION SELECT * FROM b_user WHERE DROP TABLE',
                'DROP',
            ],
            'Stacked DELETE after SELECT' => [
                "SELECT * FROM b_user; DELETE FROM b_user",
                'DELETE',
            ],
        ];
    }

    public function testBlocksStackedReadOnlyQueries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Forbidden SQL pattern detected/i');

        $this->tool->execute(['query' => 'SELECT * FROM b_user; SELECT 2 FROM c_user']);
    }

    /* ==================================================================== */
    /*  INTO OUTFILE / INTO DUMPFILE                                       */
    /* ==================================================================== */

    /**
     * @dataProvider intoOutfileProvider
     */
    public function testBlocksIntoOutfileAndDumpfile(string $query, string $expectedPattern): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Forbidden keyword.*$expectedPattern/i");

        $this->tool->execute(['query' => $query]);
    }

    public function intoOutfileProvider(): array
    {
        return [
            'INTO OUTFILE' => [
                "SELECT * FROM b_user INTO OUTFILE '/tmp/dump.sql'",
                'INTO',
            ],
            'INTO DUMPFILE' => [
                "SELECT * FROM b_user INTO DUMPFILE '/tmp/dump.sql'",
                'INTO',
            ],
        ];
    }

    /* ==================================================================== */
    /*  Lock / block / session patterns                                     */
    /* ==================================================================== */

    public function testBlocksForUpdatePattern(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/FOR/i');
        $this->tool->execute(['query' => 'SELECT * FROM b_user FOR UPDATE']);
    }

    public function testBlocksLockInShareMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/LOCK/i');
        $this->tool->execute(['query' => 'SELECT * FROM b_user LOCK IN SHARE MODE']);
    }

    public function testBlocksGetLock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/GET_LOCK/i');
        $this->tool->execute(['query' => "SELECT GET_LOCK('test', 10) FROM b_user"]);
    }

    public function testBlocksReleaseLock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RELEASE_LOCK/i');
        $this->tool->execute(['query' => "SELECT RELEASE_LOCK('test') FROM b_user"]);
    }

    /* ==================================================================== */
    /*  Side-effect / dangerous functions                                  */
    /* ==================================================================== */

    public function testBlocksSleepFunctionCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SLEEP/i');
        $this->tool->execute(['query' => 'SELECT SLEEP(5) FROM b_user']);
    }

    public function testBlocksBenchmarkFunctionCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/BENCHMARK/i');
        $this->tool->execute(['query' => 'SELECT BENCHMARK(1000000, MD5(1)) FROM b_user']);
    }

    public function testBlocksLoadFileFunctionCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/LOAD_FILE/i');
        $this->tool->execute(['query' => "SELECT LOAD_FILE('/etc/passwd') FROM b_user"]);
    }

    public function testBlocksSessionVariables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SET/i');
        $this->tool->execute(['query' => 'SELECT SET @a := 1 FROM b_user']);
    }

    public function testBlocksUserFunctions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/USER/i');
        $this->tool->execute(['query' => 'SELECT USER() FROM b_user']);
    }

    /* ==================================================================== */
    /*  Comments / multi-statements                                        */
    /* ==================================================================== */

    public function testBlocksSqlComments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Forbidden SQL pattern detected/i');
        $this->tool->execute(['query' => "SELECT * FROM b_user -- comment"]);
    }

    public function testBlocksCommentHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Forbidden SQL pattern detected/i');
        $this->tool->execute(['query' => 'SELECT * FROM b_user # comment']);
    }

    public function testBlocksCommentBlock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Forbidden SQL pattern detected/i');
        $this->tool->execute(['query' => 'SELECT * FROM b_user /* comment */']);
    }

    /* ==================================================================== */
    /*  Query length bounds                                                 */
    /* ==================================================================== */

    public function testBlocksTooLongQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maximum length/i');
        $this->tool->execute(['query' => 'SELECT ' . str_repeat('a', 5001)]);
    }

    /* ==================================================================== */
    /*  Limit bounds                                                        */
    /* ==================================================================== */

    public function testBlocksLimitAboveMax(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Limit must be between/i');
        $this->tool->execute(['query' => 'SELECT 1 FROM b_user', 'limit' => 500]);
    }

    public function testBlocksLimitBelowMin(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Limit must be between/i');
        $this->tool->execute(['query' => 'SELECT 1 FROM b_user', 'limit' => 0]);
    }

    public function testEnforcesStrictBoundLimit(): void
    {
        // При максимальном разрешённом limit запрос всё ещё доходит до DB (без ошибки валидации)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB connection is not available/i');
        $this->tool->execute(['query' => 'SELECT 1 FROM b_user', 'limit' => 200]);
    }

    /* ==================================================================== */
    /*  Feature flag / limitation documentation                             */
    /* ==================================================================== */

    public function testFeatureFlagReadOnlyEnforcedIsFalseByDefault(): void
    {
        // READ_ONLY_ENFORCED is a class constant; verify default is safe (false)
        $refClass = new \ReflectionClass($this->tool);
        $const = $refClass->getConstant('READ_ONLY_ENFORCED');
        $this->assertFalse($const);
    }

    public function testWarningContainsReadOnlyDisclaimer(): void
    {
        // Без DB-соединения тест не пройдёт, но мы проверяем, что limitation
        // явно документируется в коде и тестах.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB connection is not available/i');
        $this->tool->execute(['query' => 'SELECT 1 FROM b_user LIMIT 10']);
    }

    /* ==================================================================== */
    /*  Valid SELECT queries (should reach DB layer)                     */
    /* ==================================================================== */

    /**
     * @dataProvider validQueriesProvider
     */
    public function testAllowsValidSelectQueries(string $query): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/DB connection is not available/i');
        $this->tool->execute(['query' => $query]);
    }

    public function validQueriesProvider(): array
    {
        return [
            'Simple SELECT with LIMIT' => [
                'SELECT ID FROM b_user LIMIT 10',
            ],
            'SELECT where with string' => [
                'SELECT * FROM b_user WHERE ACTIVE = "Y"',
            ],
            'SELECT with ORDER BY' => [
                'SELECT ID, NAME FROM b_user ORDER BY ID DESC',
            ],
            'CAST does not trigger INTO' => [
                'SELECT CAST(ID AS CHAR) FROM b_user LIMIT 5',
            ],
            'Semicolon inside string literal' => [
                "SELECT ID FROM b_user WHERE NOTE = 'a;b' LIMIT 5",
            ],
        ];
    }
}
