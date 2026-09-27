<?php

namespace Bitrix24\Mcp\Tools;

/**
 * Инструмент для безопасного чтения файлов проекта с жёсткими ограничениями.
 */
class FileReader extends AbstractTool
{
    private const ALLOWED_PATHS = [
        '/local/', '/bitrix/modules/', '/bitrix/components/', '/bitrix/templates/',
    ];

    private const FORBIDDEN_FILES = [
        '/bitrix/php_interface/dbconn.php',
        '/.env', '/local/.env',
    ];

    private const SECRET_BASENAMES = [
        '.env', '.env.local', '.env.production', '.env.staging',
        '.secret', '.credentials', '.passwd', '.htpasswd', '.htaccess',
        'secret.env', 'dbconn.php',
    ];

    private const SECRET_EXTENSIONS = [
        'key', 'pem', 'p12', 'pfx', 'crt', 'cer', 'csr',
    ];

    private const MAX_PATH_LENGTH = 2048;
    private const MAX_CONTENT_SIZE = 1048576; // 1 MiB
    private const MAX_LINES = 10000;

    public function getName(): string
    {
        return 'file_reader';
    }

    public function getDescription(): string
    {
        return 'Безопасное чтение файлов проекта '
            . 'с ограничениями доступа. '
            . 'Разрешено чтение из разрешённых корней.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'path' => [
                    'type' => 'string',
                    'description' => 'Путь к файлу относительно корня сайта',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'Начальная строка для чтения (с 1, опционально)',
                    'default' => null,
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'Конечная строка для чтения (опционально)',
                    'default' => null,
                ],
            ],
            'required' => ['path'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['path']);

        $relativePath = $this->validateStringParam($arguments['path'] ?? '', 'path');
        $startLineArg = $arguments['start_line'] ?? null;
        $endLineArg = $arguments['end_line'] ?? null;

        $startLine = $startLineArg !== null
            ? $this->validatePositiveInteger($startLineArg, 'start_line', self::MAX_LINES)
            : null;
        $endLine = $endLineArg !== null
            ? $this->validatePositiveInteger($endLineArg, 'end_line', self::MAX_LINES)
            : null;

        $documentRoot = $this->getCanonicalDocumentRoot();
        $normalizedPath = $this->normalizeAndValidatePath($relativePath, $documentRoot);

        $fullPath = $documentRoot . DIRECTORY_SEPARATOR . $normalizedPath;
        $canonicalPath = $this->canonicalPathWithCheck($fullPath);
        if ($canonicalPath === false) {
            $basename = basename($normalizedPath);
            $secretBlocked = false;
            foreach (self::SECRET_BASENAMES as $secret) {
                if ($basename === $secret || $basename === $secret . '.php' || $basename === $secret . '.txt') {
                    $secretBlocked = true;
                    break;
                }
            }
            if ($secretBlocked) {
                throw new \InvalidArgumentException('Access to secret file denied: ' . $basename);
            }
            // Also reject nested secret basenames even when file does not exist
            $normalizedParts = explode('/', $normalizedPath);
            foreach ($normalizedParts as $part) {
                foreach (self::SECRET_BASENAMES as $secret) {
                    if ($part === $secret) {
                        throw new \InvalidArgumentException('Access to secret file denied: ' . $basename);
                    }
                }
            }
            throw new \InvalidArgumentException('File not found: ' . $relativePath);
        }

        $this->assertInsideDocumentRoot($canonicalPath, $documentRoot);
        $this->assertAllowedPaths($canonicalPath, $documentRoot);
        $this->assertNotSecretFile($canonicalPath, $documentRoot);

        $realPath = realpath($canonicalPath);
        if (
            $realPath === false
            || !is_file($realPath)
            || !is_readable($realPath)
        ) {
            throw new \InvalidArgumentException('File not found or not readable: ' . $relativePath);
        }
        $this->assertInsideDocumentRoot($realPath, $documentRoot);

        // Symlink check: realpath must resolve to the same file and stay inside root
        $symlinkTarget = @readlink($canonicalPath);
        if ($symlinkTarget !== false) {
            $targetPath = $this->resolveSymlinkTarget($canonicalPath, $symlinkTarget, $documentRoot);
            $targetReal = $this->canonicalPathWithCheck($targetPath);
            if ($targetReal === false || $targetReal !== $realPath) {
                throw new \InvalidArgumentException('Symlink escape detected');
            }
            $this->assertInsideDocumentRoot($targetReal, $documentRoot);
        }
        // Ensure realpath does not point outside root (symlink to allowed file)
        $this->assertInsideDocumentRoot($realPath, $documentRoot);

        $content = $this->readFileLimited($realPath);
        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($startLine !== null || $endLine !== null) {
            $startIndex = max(0, ($startLine ?? 1) - 1);
            $endIndex = $endLine !== null ? min($totalLines, $endLine) : $totalLines;
            if ($endIndex < $startIndex) {
                $endIndex = $startIndex;
            }
            $length = max(0, $endIndex - $startIndex);
            $lines = array_slice($lines, $startIndex, $length);
            $content = implode("\n", $lines);
            $readLines = count($lines);
        } else {
            $readLines = $totalLines;
        }

        return [
            'path' => '/' . $normalizedPath,
            'real_path' => '/' . ltrim(
                str_replace($documentRoot . DIRECTORY_SEPARATOR, '', $realPath),
                DIRECTORY_SEPARATOR
            ),
            'content' => $content,
            'total_lines' => $totalLines,
            'read_lines' => $readLines,
            'size' => filesize($realPath),
            'mime_type' => $this->getMimeType($realPath),
        ];
    }

    private function validateStringParam(?string $value, string $name): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException($name . ' must be a non-empty string');
        }
        if (mb_strlen($value) > self::MAX_PATH_LENGTH) {
            throw new \InvalidArgumentException($name . ' exceeds maximum length');
        }
        return $value;
    }

    private function validatePositiveInteger($value, string $name, int $maxValue): int
    {
        $val = is_int($value) ? $value : (int) $value;
        if ($val < 1) {
            throw new \InvalidArgumentException($name . ' must be positive');
        }
        if ($val > $maxValue) {
            throw new \InvalidArgumentException($name . ' exceeds maximum of ' . $maxValue);
        }
        return $val;
    }

    private function getCanonicalDocumentRoot(): string
    {
        $root = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($root === '') {
            throw new \RuntimeException('DOCUMENT_ROOT is not set');
        }
        $canonical = $this->canonicalPathWithCheck($root);
        if ($canonical === false) {
            throw new \RuntimeException('DOCUMENT_ROOT is invalid');
        }
        return $canonical;
    }

    private function canonicalPathWithCheck(string $path): string|false
    {
        if (str_contains($path, "\0")) {
            return false;
        }
        $canonical = realpath($path);
        return $canonical === false ? false : $canonical;
    }

    private function normalizeAndValidatePath(string $path, string $documentRoot): string
    {
        $originalPath = $path;
        $normalized = ltrim($path, '/');
        $normalized = rtrim($normalized, '/');
        $normalized = str_replace('\\', '/', $normalized);

        // Block null bytes
        if (str_contains($normalized, "\0")) {
            throw new \InvalidArgumentException('Invalid path contains null bytes');
        }

        // Block traversal attempts before resolving
        $parts = explode('/', $normalized);
        $cleanParts = [];
        foreach ($parts as $part) {
            if ($part === '..') {
                throw new \InvalidArgumentException('Path traversal is not allowed: ' . $originalPath);
            }
            if ($part !== '' && $part !== '.') {
                $cleanParts[] = $part;
            }
        }
        $normalized = implode('/', $cleanParts);

        $fullPath = $documentRoot . DIRECTORY_SEPARATOR . $normalized;
        $canonical = $this->canonicalPathWithCheck($fullPath);
        if ($canonical === false) {
            // If file does not exist yet, still validate structure
            $canonical = $documentRoot . DIRECTORY_SEPARATOR . $normalized;
            // Check no traversal in intermediate segments
            $parts = explode('/', $normalized);
            foreach ($parts as $part) {
                if ($part === '..') {
                    throw new \InvalidArgumentException('Path traversal is not allowed');
                }
            }
            return $normalized;
        }

        return $normalized;
    }

    private function assertInsideDocumentRoot(string $path, string $documentRoot): void
    {
        $documentRootNormalized = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $pathNormalized = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($pathNormalized, $documentRootNormalized)) {
            throw new \InvalidArgumentException('Access denied: file is outside document root');
        }
    }

    private function assertAllowedPaths(string $canonicalPath, string $documentRoot): void
    {
        $relativePath = '/' . ltrim(
            str_replace($documentRoot . DIRECTORY_SEPARATOR, '', $canonicalPath),
            DIRECTORY_SEPARATOR
        );
        $relativePath = '/' . trim(
            str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
            '/'
        );

        $allowed = false;
        foreach (self::ALLOWED_PATHS as $allowedPath) {
            if (str_starts_with($relativePath, $allowedPath) || $relativePath === $allowedPath) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new \InvalidArgumentException('Access denied. Allowed paths: ' . implode(', ', self::ALLOWED_PATHS));
        }
    }

    private function assertNotSecretFile(string $canonicalPath, string $documentRoot): void
    {
        $basename = basename($canonicalPath);
        $relativePath = '/' . trim(
            str_replace(DIRECTORY_SEPARATOR, '/', $canonicalPath),
            '/'
        );
        $relativePath = '/' . ltrim(
            str_replace($documentRoot . DIRECTORY_SEPARATOR, '', $canonicalPath),
            DIRECTORY_SEPARATOR
        );
        $relativePath = '/' . trim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');

        // Check secret basenames at any nesting level (basename or nested component)
        $relativePathClean = '/' . trim(
            str_replace(DIRECTORY_SEPARATOR, '/', $canonicalPath),
            '/'
        );
        $relativePathClean = '/' . ltrim(
            str_replace($documentRoot . DIRECTORY_SEPARATOR, '', $relativePathClean),
            DIRECTORY_SEPARATOR
        );
        $relativePathClean = '/' . trim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePathClean), '/');

        $parts = explode('/', trim($relativePathClean, '/'));
        foreach ($parts as $part) {
            $partLower = strtolower($part);
            $extension = strtolower(pathinfo($partLower, PATHINFO_EXTENSION));
            $isSecretName = in_array($partLower, self::SECRET_BASENAMES, true)
                || str_starts_with($partLower, '.env.')
                || in_array($extension, self::SECRET_EXTENSIONS, true)
                || str_contains($partLower, 'credential')
                || str_contains($partLower, 'password')
                || str_contains($partLower, 'private_key');
            if ($isSecretName) {
                throw new \InvalidArgumentException('Access to secret file denied: ' . $basename);
            }
        }

        // Forbidden exact paths
        foreach (self::FORBIDDEN_FILES as $forbidden) {
            $normalizedRelative = '/' . trim(
                str_replace(DIRECTORY_SEPARATOR, '/', $relativePath),
                '/'
            );
            $normalizedForbidden = '/' . trim(
                str_replace(DIRECTORY_SEPARATOR, '/', $forbidden),
                '/'
            );
            $cond = $normalizedRelative === $normalizedForbidden
                || str_contains($normalizedRelative, $normalizedForbidden);
            if ($cond) {
                throw new \InvalidArgumentException('Access to this file is forbidden');
            }
        }
    }

    private function resolveSymlinkTarget(string $canonicalPath, string $symlinkTarget, string $documentRoot): string
    {
        $dir = dirname($canonicalPath);
        $target = $symlinkTarget;
        if (!str_starts_with($target, DIRECTORY_SEPARATOR)) {
            $target = $dir . DIRECTORY_SEPARATOR . $target;
        }
        return $target;
    }

    private function readFileLimited(string $filePath): string
    {
        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new \InvalidArgumentException('Failed to get file size: ' . $filePath);
        }
        if ($fileSize > self::MAX_CONTENT_SIZE) {
            throw new \InvalidArgumentException('File exceeds maximum allowed size');
        }
        $content = @file_get_contents($filePath, false, null, 0, self::MAX_CONTENT_SIZE + 1);
        if ($content === false) {
            throw new \RuntimeException('Failed to read file: ' . $filePath);
        }
        if (strlen($content) > self::MAX_CONTENT_SIZE) {
            throw new \InvalidArgumentException('File exceeds maximum allowed size');
        }
        return $content;
    }

    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'php' => 'text/x-php',
            'js' => 'text/javascript',
            'css' => 'text/css',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
        ];
        return $mimeTypes[$extension] ?? 'text/plain';
    }
}
