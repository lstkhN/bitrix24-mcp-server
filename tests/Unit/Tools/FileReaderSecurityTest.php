<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\FileReader;

/**
 * Тесты безопасности для FileReader (path traversal, allowlist, forbidden files)
 *
 * Используют реальный временный DOCUMENT_ROOT, чтобы проверять поведение
 * на существующих файлах, а не только до-realpath валидацию.
 */
class FileReaderSecurityTest extends TestCase
{
    private FileReader $tool;
    private string $documentRoot;
    private ?string $originalDocumentRoot;

    protected function setUp(): void
    {
        $this->tool = new FileReader();

        $this->documentRoot = sys_get_temp_dir() . '/bitrix24_mcp_' . uniqid('', true);

        // Структура, имитирующая корень сайта Битрикс
        $dirs = [
            'local/php_interface',
            'bitrix/modules/main',
            'bitrix/components/bitrix/menu',
            'bitrix/php_interface',
            'upload',
        ];
        foreach ($dirs as $dir) {
            mkdir($this->documentRoot . '/' . $dir, 0755, true);
        }

        file_put_contents($this->documentRoot . '/local/php_interface/init.php', "<?php // allowed\n");
        file_put_contents($this->documentRoot . '/bitrix/modules/main/include.php', "<?php // allowed\n");
        file_put_contents($this->documentRoot . '/bitrix/php_interface/dbconn.php', "<?php \$DB = 'secret';\n");
        file_put_contents($this->documentRoot . '/local/.env', "DB_PASSWORD=secret\n");
        file_put_contents($this->documentRoot . '/upload/secret.txt', "upload secret\n");

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

    /**
     * @dataProvider forbiddenPathsProvider
     */
    public function testBlocksForbiddenPaths(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tool->execute(['path' => $path]);
    }

    public function forbiddenPathsProvider(): array
    {
        return [
            'Path traversal with ../' => [
                '../../../etc/passwd',
            ],
            'Path traversal in middle' => [
                '/local/../../../etc/passwd',
            ],
            'Traversal to outside dir root' => [
                '/upload/../.env.outside',
            ],
            'dbconn.php (exists on disk, must be blocked)' => [
                '/bitrix/php_interface/dbconn.php',
            ],
            'Root .env' => [
                '/.env',
            ],
            'local .env (exists on disk, must be blocked)' => [
                '/local/.env',
            ],
            'Upload directory' => [
                '/upload/secret.txt',
            ],
            'Bitrix cache' => [
                '/bitrix/cache/data.php',
            ],
            'Dot-dot-slash normalization trick' => [
                '/local/php_interface/../../.env',
            ],
        ];
    }

    /**
     * @dataProvider allowedPathsProvider
     */
    public function testAllowsValidPaths(string $path, string $expectedRealPath): void
    {
        $result = $this->tool->execute(['path' => $path]);

        $this->assertSame("<?php // allowed\n", $result['content']);
        // realpath() может резолвить синклинки (например, /tmp -> /private/tmp на macOS),
        // поэтому проверяем суффикс относительного пути
        $this->assertStringEndsWith($expectedRealPath, $result['real_path']);
        $this->assertFileExists(realpath($this->documentRoot . $expectedRealPath));
    }

    public function allowedPathsProvider(): array
    {
        return [
            'Local file' => [
                'local/php_interface/init.php',
                '/local/php_interface/init.php',
            ],
            'Module file' => [
                'bitrix/modules/main/include.php',
                '/bitrix/modules/main/include.php',
            ],
        ];
    }

    public function testRequiresPathParameter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Required parameter 'path' is missing");

        $this->tool->execute([]);
    }
}
