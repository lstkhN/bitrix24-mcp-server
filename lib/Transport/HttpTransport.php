<?php

namespace Bitrix24\Mcp\Transport;

use Bitrix\Main\Config\Option;

const HTTP_MAX_BODY_SIZE = 1048576; // 1 MB
const HTTP_MAX_JSON_DEPTH = 100;
const HTTP_ALLOWED_METHODS = 'POST, GET, DELETE, OPTIONS';
const HTTP_ALLOWED_HEADERS = 'Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID';

/**
 * HTTP транспорт для MCP (используется для REST API подключений)
 * Обрабатывает POST запросы с JSON-RPC телом и проверяет авторизацию
 */
class HttpTransport extends AbstractTransport
{
    private bool $requestReceived = false;
    private ?array $cachedResponse = null;

    /**
     * Получение запроса из HTTP POST
     */
    public function receive(): ?array
    {
        // HTTP транспорт обрабатывает только один запрос за раз
        if ($this->requestReceived) {
            return null;
        }

        $this->requestReceived = true;

        // Проверка метода запроса
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            throw new \RuntimeException('Only POST method is allowed');
        }

        // Проверка Content-Type
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') === false) {
            throw new \RuntimeException('Content-Type must be application/json');
        }

        // Проверка авторизации
        $this->checkAuthorization();

        // Чтение тела запроса
        $body = file_get_contents('php://input');

        if ($body === false) {
            throw new \RuntimeException('Failed to read request body');
        }

        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > HTTP_MAX_BODY_SIZE) {
            throw new \RuntimeException('Request body exceeds maximum allowed size');
        }
        if (strlen($body) > HTTP_MAX_BODY_SIZE) {
            throw new \RuntimeException('Request body exceeds maximum allowed size');
        }

        if ($body === '') {
            throw new \RuntimeException('Request body is empty');
        }

        $decoded = $this->decodeJson($body, HTTP_MAX_JSON_DEPTH);

        return $decoded;
    }

    protected function decodeJson(string $json, int $maxDepth = 512): ?array
    {
        $data = json_decode($json, true, $maxDepth);

        if (json_last_error() !== JSON_ERROR_NONE) {
            if (json_last_error() === JSON_ERROR_DEPTH) {
                throw new \RuntimeException('JSON decode error: maximum depth exceeded');
            }
            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg());
        }

        return $data;
    }

    /**
     * Получить последний ответ (для тестирования)
     */
    public function getLastResponse(): ?array
    {
        return $this->cachedResponse;
    }

    /**
     * Отправка ответа в HTTP response
     */
    public function send(array $response): void
    {
        $this->cachedResponse = $response;

        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $this->sendCorsHeaders($requestOrigin);

        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        echo $this->encodeJson($response);
    }

    /**
     * Проверка токена авторизации
     */
    private function getAllowedCorsOrigins(): array
    {
        $raw = Option::get('bitrix24.mcp', 'cors_allowed_origins', '');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $origins = array_map('trim', explode(',', (string)$raw));
        $allowed = [];
        foreach ($origins as $o) {
            if ($o !== '') {
                $allowed[] = $o;
            }
        }
        return $allowed;
    }

    private function isAllowedCorsOrigin(string $origin): bool
    {
        $allowed = $this->getAllowedCorsOrigins();
        if ($allowed === []) {
            return false;
        }
        foreach ($allowed as $a) {
            if ($a === $origin) {
                return true;
            }
        }
        return false;
    }

    private function sendCorsHeaders(string $origin = ''): void
    {
        header('Vary: Origin');
        if ($origin !== '' && $this->isAllowedCorsOrigin($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID');
            header('Access-Control-Expose-Headers: Mcp-Session-Id');
        }
    }

    private function checkAuthorization(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (empty($authHeader)) {
            http_response_code(401);
            throw new \RuntimeException('Authorization header is required');
        }

        // Извлекаем токен из заголовка "Bearer {token}"
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            http_response_code(401);
            throw new \RuntimeException('Invalid authorization format. Use: Bearer {token}');
        }

        $token = $matches[1];

        // Получаем токен из настроек
        $expectedToken = Option::get('bitrix24.mcp', 'auth_token', '');

        if (empty($expectedToken)) {
            http_response_code(500);
            throw new \RuntimeException('Server authorization token is not configured');
        }

        // Сравниваем токены
        if (!hash_equals($expectedToken, $token)) {
            http_response_code(403);
            throw new \RuntimeException('Invalid authorization token');
        }
    }
}
