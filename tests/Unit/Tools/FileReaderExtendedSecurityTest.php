<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\FileReader;

/**
 * Расширенные тесты безопасности FileReader (symlink, nested secrets,
 * oversized input, regression allowed files, traversal, /etc)
 */
class FileReaderExtendedSecurityTest extends TestCase
{
    private FileReader $tool;
    private string $documentRoot;
    private ?string $originalDocumentRoot;

    protected function setUp(): void
    {
        $this->tool = new FileReader();
        $this->documentRoot = sys_get_temp_dir() . '/bitrix24_mcp_ext_' . uniqid('', true);

        $dirs = [
            'local/php_interface',
            'bitrix/modules/main',
            'upload',
            'local/components/bitrix/test',
        ];
        foreach ($dirs as $dir) {
            mkdir($this->documentRoot . '/' . $dir, 0755, true);
        }

        file_put_contents($this->documentRoot . '/local/php_interface/init.php', "<?php // init\n");
        file_put_contents($this->documentRoot . '/local/components/bitrix/test/init.php', "<?php // comp\n");
        file_put_contents($this->documentRoot . '/local/nested/.env', "SECRET=1\n");
        file_put_contents($this->documentRoot . '/upload/secret.txt', "secret\n");

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

    public function testBlocksNestedEnvFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Access to secret file denied/');
        $this->tool->execute(['path' => 'local/nested/.env']);
    }

    public function testBlocksTraversalToEtc(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/traversal/');
        $this->tool->execute(['path' => '/local/../../../../etc/passwd']);
    }

    public function testBlocksTraversalToOutsideRoot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/traversal/');
        $this->tool->execute([
            'path' => 'local/php_interface/init.php/../../.env',
        ]);
    }

    public function testBlocksSymlinkEscape(): void
    {
        $symlinkDir = $this->documentRoot . '/symlink_escape';
        mkdir($symlinkDir, 0755, true);
        symlink('/etc', $symlinkDir . '/etc_link');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Access denied/');
        $this->tool->execute(['path' => 'symlink_escape/etc_link/passwd']);
    }

    public function testAllowsRegressionLocalFile(): void
    {
        $result = $this->tool->execute(['path' => 'local/php_interface/init.php']);
        $this->assertSame("<?php // init\n", $result['content']);
        $this->assertStringEndsWith('/local/php_interface/init.php', $result['real_path']);
    }

    public function testAllowsRegressionComponentFile(): void
    {
        $result = $this->tool->execute(['path' => 'local/components/bitrix/test/init.php']);
        $this->assertStringContainsString('comp', $result['content']);
    }

    public function testBlocksOversizedPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $long = str_repeat('a', 3000);
        $this->tool->execute(['path' => $long]);
    }

    public function testBlocksOversizedStartLine(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds/');
        $this->tool->execute([
            'path' => 'local/php_interface/init.php',
            'start_line' => 20000,
        ]);
    }

    public function testLimitsContentToMaxSize(): void
    {
        $bigFile = $this->documentRoot . '/local/big.php';
        file_put_contents($bigFile, str_repeat('A', 2 * 1048576));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/maximum/');
        $this->tool->execute([
            'path' => 'local/big.php',
            'start_line' => 1,
            'end_line' => 100000,
        ]);
    }

    public function testSecretBasenameBlock(): void
    {
        file_put_contents($this->documentRoot . '/local/secret.env', 'X=1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret/');
        $this->tool->execute(['path' => 'local/secret.env']);
    }

    public function testSecretBasenameNested(): void
    {
        file_put_contents($this->documentRoot . '/bitrix/modules/main/.env', 'X=1');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/secret/');
        $this->tool->execute(['path' => 'bitrix/modules/main/.env']);
    }
}
