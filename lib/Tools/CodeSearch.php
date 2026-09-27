<?php

namespace Bitrix24\Mcp\Tools;

use Bitrix\Main\Config\Option;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Инструмент для поиска по кодовой базе с жёсткими ограничениями безопасности.
 */
class CodeSearch extends AbstractTool
{
    private const EXCLUDED_DIRS = [
        'vendor', 'node_modules', 'upload', 'cache', '.git', '.idea', '.vscode',
    ];

    private const SECRET_BASENAMES = [
        '.env', '.env.local', '.env.production', '.env.staging',
        '.secret', '.credentials', '.passwd', '.htpasswd', '.htaccess',
        'secret.env',
    ];

    private const SECRET_EXTENSIONS = [
        'key', 'pem', 'p12', 'pfx', 'crt', 'cer', 'csr',
    ];

    private const MAX_PATTERN_LENGTH = 2048;
    private const MAX_PATH_LENGTH = 2048;
    private const MAX_EXT_COUNT = 20;
    private const MAX_EXT_LENGTH = 20;
    private const MAX_FILE_SIZE = 1048576; // 1 MiB
    private const MAX_RESULT_CONTENT_LENGTH = 8192;
    private const MAX_CONTEXT_LINES = 20;
    private const MAX_MAX_RESULTS = 500;
    private const MAX_MAX_FILES = 5000;

    public function getName(): string
    {
        return 'code_search';
    }

    public function getDescription(): string
    {
        return 'Поиск текста или регулярного выражения в файлах проекта с контекстом. '
            . 'Поддерживает фильтрацию по расширениям и ограничения результатов.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'Текст или регулярное выражение для поиска',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'Путь для поиска относительно корня сайта (по умолчанию /local)',
                    'default' => '/local',
                ],
                'extensions' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Расширения файлов для поиска (например: ["php", "js"])',
                    'default' => ['php'],
                ],
                'max_results' => [
                    'type' => 'integer',
                    'description' => 'Максимальное количество результатов',
                    'default' => 50,
                ],
                'context_lines' => [
                    'type' => 'integer',
                    'description' => 'Количество строк контекста до и после совпадения',
                    'default' => 3,
                ],
                'case_sensitive' => [
                    'type' => 'boolean',
                    'description' => 'Учитывать регистр при поиске',
                    'default' => true,
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $arguments): array
    {
        $this->validateRequired($arguments, ['pattern']);

        $pattern = $this->validatePattern($arguments['pattern'] ?? '');
        $pathParam = $this->validateStringParam($arguments['path'] ?? '/local', 'path');
        $extensions = $this->validateExtensions($arguments['extensions'] ?? ['php']);
        $maxResults = $this->validatePositiveInteger(
            $arguments['max_results'] ?? 50,
            'max_results',
            self::MAX_MAX_RESULTS
        );
        $contextLines = $this->validateNonNegativeInteger(
            $arguments['context_lines'] ?? 3,
            'context_lines',
            self::MAX_CONTEXT_LINES
        );
        $caseSensitive = isset($arguments['case_sensitive']) ? (bool) $arguments['case_sensitive'] : true;

        $documentRoot = $this->getCanonicalDocumentRoot();
        $searchPath = $this->resolveSearchPath($pathParam, $documentRoot);

        $maxDepth = max(1, min(30, (int) Option::get('bitrix24.mcp', 'max_code_search_depth', 10)));
        $maxFiles = max(10, min(self::MAX_MAX_FILES, (int) Option::get('bitrix24.mcp', 'max_code_search_files', 1000)));
        $maxFileSize = max(1024, min(self::MAX_FILE_SIZE, (int) Option::get('bitrix24.mcp', 'max_file_size', 1048576)));

        if (!is_dir($searchPath)) {
            throw new \InvalidArgumentException('Search path does not exist: ' . $pathParam);
        }

        $canonicalSearchPath = $this->canonicalPathWithCheck($searchPath);
        $this->assertInsideDocumentRoot($canonicalSearchPath, $documentRoot);

        $results = [];
        $filesScanned = 0;
        $truncated = false;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $canonicalSearchPath,
                RecursiveDirectoryIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $iterator->setMaxDepth($maxDepth);

        foreach ($iterator as $fileInfo) {
            $file = $fileInfo;
            if (!$file->isFile()) {
                continue;
            }

            $pathname = $file->getPathname();
            $canonicalFile = $this->canonicalPathWithCheck($pathname);
            if ($canonicalFile === false) {
                continue;
            }
            $this->assertInsideDocumentRoot($canonicalFile, $documentRoot);

            // Symlink escape check
            $realFile = realpath($canonicalFile);
            if ($realFile === false || !is_file($realFile)) {
                continue;
            }
            $this->assertInsideDocumentRoot($realFile, $documentRoot);

            // Secret basename guard: only throw for allowed extensions
            $basename = basename($canonicalFile);
            $basenameLower = strtolower($basename);
            $ext = strtolower($file->getExtension());
            $blocked = in_array($basenameLower, self::SECRET_BASENAMES, true)
                || str_starts_with($basenameLower, '.env.')
                || in_array($ext, self::SECRET_EXTENSIONS, true)
                || str_contains($basenameLower, 'credential')
                || str_contains($basenameLower, 'password')
                || str_contains($basenameLower, 'private_key');
            $extensionsLower = array_map('strtolower', $extensions);
            if ($blocked && in_array($ext, $extensionsLower, true)) {
                throw new \InvalidArgumentException('Access to secret file denied: ' . $basename);
            }
            if ($blocked && !in_array($ext, $extensionsLower, true)) {
                continue;
            }

            if (!in_array($ext, $extensionsLower, true)) {
                continue;
            }

            if ($filesScanned >= $maxFiles) {
                $truncated = true;
                break;
            }

            $fileSize = $file->getSize();
            if ($fileSize > $maxFileSize) {
                continue;
            }
            if ($fileSize <= 0) {
                continue;
            }

            if ($this->isExcluded($canonicalFile)) {
                continue;
            }

            $filesScanned++;

            try {
                $matches = $this->searchInFile($canonicalFile, $pattern, $caseSensitive, $contextLines);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($matches as $match) {
                $results[] = [
                    'file' => str_replace($documentRoot . DIRECTORY_SEPARATOR, '', $canonicalFile),
                    'line' => $match['line'],
                    'content' => mb_substr($match['content'], 0, self::MAX_RESULT_CONTENT_LENGTH),
                    'context_before' => array_map(
                        fn(string $line): string => mb_substr($line, 0, self::MAX_RESULT_CONTENT_LENGTH),
                        $match['context_before']
                    ),
                    'context_after' => array_map(
                        fn(string $line): string => mb_substr($line, 0, self::MAX_RESULT_CONTENT_LENGTH),
                        $match['context_after']
                    ),
                ];

                if (count($results) >= $maxResults) {
                    $truncated = true;
                    break 2;
                }
            }
        }

        return [
            'pattern' => $pattern,
            'path' => $pathParam,
            'results' => $results,
            'total_matches' => count($results),
            'files_scanned' => $filesScanned,
            'truncated' => $truncated || count($results) >= $maxResults,
        ];
    }

    private function validatePattern(string $pattern): string
    {
        if ($pattern === '') {
            throw new \InvalidArgumentException('Pattern must be non-empty');
        }
        if (mb_strlen($pattern) > self::MAX_PATTERN_LENGTH) {
            throw new \InvalidArgumentException('Pattern exceeds maximum length of ' . self::MAX_PATTERN_LENGTH);
        }
        // If it looks like regex, validate it
        if ((bool) @preg_match('/^\/.*\/[imsxuADSUXJ]*$/', $pattern)) {
            $testResult = @preg_match($pattern, 'test');
            if ($testResult === false) {
                throw new \InvalidArgumentException('Invalid regular expression: ' . $pattern);
            }
        }
        return $pattern;
    }

    private function validateStringParam(?string $value, string $name, int $maxLength = self::MAX_PATH_LENGTH): string
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException($name . ' must be a string');
        }
        if (mb_strlen($value) > $maxLength) {
            throw new \InvalidArgumentException($name . ' exceeds maximum length');
        }
        return $value;
    }

    private function validateExtensions(?array $extensions): array
    {
        if (!is_array($extensions)) {
            throw new \InvalidArgumentException('extensions must be an array');
        }
        if (count($extensions) === 0 || count($extensions) > self::MAX_EXT_COUNT) {
            throw new \InvalidArgumentException('extensions count must be between 1 and ' . self::MAX_EXT_COUNT);
        }
        $validated = [];
        foreach ($extensions as $ext) {
            if (!is_string($ext) || $ext === '' || mb_strlen($ext) > self::MAX_EXT_LENGTH) {
                throw new \InvalidArgumentException('Invalid extension: must be non-empty string');
            }
            $ext = strtolower($ext);
            // Block extensions that contain path separators or dots (e.g. .env, ../something)
            if (str_contains($ext, '.') || str_contains($ext, '/') || str_contains($ext, '\\')) {
                throw new \InvalidArgumentException('Invalid extension: ' . $ext);
            }
            $validated[] = $ext;
        }
        return array_values(array_unique($validated, SORT_STRING));
    }

    private function validatePositiveInteger(?int $value, string $name, int $upperBound): int
    {
        $val = is_int($value) ? $value : (int) $value;
        if ($val < 1) {
            throw new \InvalidArgumentException($name . ' must be positive');
        }
        if ($val > $upperBound) {
            throw new \InvalidArgumentException($name . ' exceeds maximum of ' . $upperBound);
        }
        return $val;
    }

    private function validateNonNegativeInteger(?int $value, string $name, int $upperBound): int
    {
        $val = is_int($value) ? $value : (int) $value;
        if ($val < 0) {
            throw new \InvalidArgumentException($name . ' must be non-negative');
        }
        if ($val > $upperBound) {
            throw new \InvalidArgumentException($name . ' exceeds maximum of ' . $upperBound);
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
        // Ensure path is absolute and does not contain null bytes
        if (str_contains($path, "\0")) {
            return false;
        }
        $canonical = realpath($path);
        return $canonical === false ? false : $canonical;
    }

    private function resolveSearchPath(string $pathParam, string $documentRoot): string
    {
        $normalized = ltrim($pathParam, '/');
        $normalized = rtrim($normalized, '/');
        $normalized = str_replace('\\', '/', $normalized);

        // Block any traversal attempts before resolving
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException('Path traversal is not allowed');
        }
        // Block null bytes
        if (str_contains($normalized, "\0")) {
            throw new \InvalidArgumentException('Invalid search path: ' . $pathParam);
        }

        $fullPath = $documentRoot . DIRECTORY_SEPARATOR . $normalized;
        $canonical = $this->canonicalPathWithCheck($fullPath);
        if ($canonical === false) {
            throw new \InvalidArgumentException('Invalid search path: ' . $pathParam);
        }
        return $canonical;
    }

    private function assertInsideDocumentRoot(string $path, string $documentRoot): void
    {
        $documentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        // Direct comparison after normalization
        if (str_starts_with($path, $documentRoot) === false) {
            throw new \InvalidArgumentException('Access denied: path is outside document root');
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDED_DIRS as $excluded) {
            $search = DIRECTORY_SEPARATOR . $excluded . DIRECTORY_SEPARATOR;
            $searchEnd = DIRECTORY_SEPARATOR . $excluded;
            if (str_contains($path, $search) || str_ends_with($path, $searchEnd)) {
                return true;
            }
        }
        return false;
    }

    private function searchInFile(string $filePath, string $pattern, bool $caseSensitive, int $contextLines): array
    {
        if (
            str_starts_with(basename($filePath), '.env')
            || basename($filePath) === '.secret'
            || basename($filePath) === '.credentials'
        ) {
            throw new \InvalidArgumentException('Access to secret file denied: ' . basename($filePath));
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return [];
        }
        // Limit read size
        if (strlen($content) > self::MAX_FILE_SIZE) {
            return [];
        }

        $lines = explode("\n", $content);
        $matches = [];

        $isRegex = $this->isRegex($pattern);

        foreach ($lines as $lineNumber => $line) {
            try {
                $found = false;

                if ($isRegex) {
                    $result = @preg_match($pattern, $line);
                    $found = ($result === 1);
                    if ($result === false) {
                        throw new \InvalidArgumentException('Invalid regular expression: ' . $pattern);
                    }
                } else {
                    $found = $caseSensitive
                        ? (str_contains($line, $pattern))
                        : (stripos($line, $pattern) !== false);
                }

                if ($found) {
                    $beforeCount = max(0, min($contextLines, self::MAX_CONTEXT_LINES));
                    $afterCount = max(0, min($contextLines, self::MAX_CONTEXT_LINES));

                    $matches[] = [
                        'line' => $lineNumber + 1,
                        'content' => rtrim($line),
                        'context_before' => $this->getContextLines($lines, $lineNumber, -$beforeCount, 0),
                        'context_after' => $this->getContextLines($lines, $lineNumber, 1, $afterCount),
                    ];
                }
            } catch (\Throwable $e) {
                // Skip lines that cause errors
                continue;
            }
        }

        return $matches;
    }

    private function getContextLines(array $lines, int $currentLine, int $offset, int $count): array
    {
        $count = max(0, min($count, self::MAX_CONTEXT_LINES));
        $context = [];
        $start = max(0, $currentLine + $offset);
        $end = min(count($lines) - 1, $currentLine + $offset + $count - 1);

        for ($i = $start; $i <= $end && $i < count($lines); $i++) {
            if ($i !== $currentLine) {
                $context[] = rtrim($lines[$i]);
            }
        }

        return $context;
    }

    private function isRegex(string $pattern): bool
    {
        return (bool) @preg_match('/^\/.*\/[imsxuADSUXJ]*$/', $pattern);
    }
}
