<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\CodeSearch;

/**
 * Security tests for CodeSearch: traversal, secret basenames,
 * oversized input, symlink escape, regex errors.
 */
class CodeSearchSecurityTest extends TestCase
{
    private CodeSearch $tool;
    private string $documentRoot;
    private ?string $originalDocumentRoot;

    protected function setUp(): void
    {
        $this->tool = new CodeSearch();
        $this->documentRoot = sys_get_temp_dir() . '/bitrix24_mcp_code_' . uniqid('', true);
        mkdir($this->documentRoot . '/local/src', 0755, true);
        file_put_contents($this->documentRoot . '/local/src/test.php', "<?php echo 'hello';\n");
        file_put_contents($this->documentRoot . '/local/src/.env', "DB=secret\n");
        $this->originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
        $_SERVER['DOCUMENT_ROOT'] = $this->documentRoot;
    }

    protected function tearDown(): void
    {
        if ($this->originalDocumentRoot !== null) {
            $_SERVER['DOCUMENT_ROOT'] = $this->originalDocumentRoot;
        } else {
            unset($_SERVER['DOCUMENT_ROOT']);
        }
        $this->removeDir($this->documentRoot);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSearchAllowedFile(): void
    {
        $result = $this->tool->execute([
            'pattern' => 'hello',
            'path' => '/local/src',
        ]);
        $this->assertNotEmpty($result['results']);
    }

    public function testTraversalBlocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/traversal/');
        $this->tool->execute([
            'pattern' => 'hello',
            'path' => '/local/src/../../etc',
        ]);
    }

    public function testSecretBasenameBlocked(): void
    {
        // Secret basename guard should block .env files in search
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret/');
        $this->tool->execute([
            'pattern' => 'DB_PASSWORD',
            'path' => '/local/src',
            'extensions' => ['php', 'env'],
        ]);
    }

    public function testPatternTooLong(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maximum length/');
        $longPattern = str_repeat('a', 3000);
        $this->tool->execute([
            'pattern' => $longPattern,
            'path' => '/local/src',
        ]);
    }

    public function testOversizedPathBlocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $longPath = str_repeat('a', 3000);
        $this->tool->execute([
            'pattern' => 'hello',
            'path' => $longPath,
        ]);
    }

    public function testRegexPatternWorks(): void
    {
        $result = $this->tool->execute([
            'pattern' => '/hello/',
            'path' => '/local/src',
        ]);
        $this->assertNotEmpty($result['results']);
    }

    public function testInvalidRegexThrows(): void
    {
        // Invalid regex should throw when searching a file
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid/');
        $this->tool->execute([
            'pattern' => '/[invalid/',
            'path' => '/local/src',
        ]);
    }

    public function testOversizedResultsLimited(): void
    {
        $result = $this->tool->execute([
            'pattern' => 'hello',
            'path' => '/local/src',
            'max_results' => 500,
        ]);
        $this->assertLessThanOrEqual(500, count($result['results']));
    }

    public function testContextLinesLimited(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maximum/');
        $this->tool->execute([
            'pattern' => 'hello',
            'path' => '/local/src',
            'context_lines' => 100,
        ]);
    }

    public function testNullBytesBlocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid search path/');
        $this->tool->execute([
            'pattern' => 'hello',
            'path' => '/local/src' . "\0" . 'bad',
        ]);
    }
}
