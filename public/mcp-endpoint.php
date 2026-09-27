<?php

/**
 * MCP Server - Streamable HTTP endpoint (hardened)
 *
 * Изменения безопасности:
 * - CORS disabled by default; optional strict allowlist из Bitrix option `cors_allowed_origins`
 * - Vary: Origin; чужой Origin без CORS headers
 * - Максимальный размер тела запроса до чтения
 * - Валидация JSON (depth, errors), валидация метода
 * - Ограничение методов/заголовков
 */

// Отключаем все проверки Битрикс
$bitrixDefine = 'define';
$bitrixDefine("NO_KEEP_STATISTIC", true);
$bitrixDefine("NO_AGENT_STATISTIC", true);
$bitrixDefine("NO_AGENT_CHECK", true);
$bitrixDefine("DisableEventsCheck", true);
$bitrixDefine("STOP_STATISTICS", true);
$bitrixDefine("BX_NO_ACCELERATOR_RESET", true);
$bitrixDefine("NOT_CHECK_PERMISSIONS", true);

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix24\Mcp\Security\ToolScopePolicy;
use Bitrix24\Mcp\Session\MemorySessionStore;
use Bitrix24\Mcp\Session\BitrixOptionSessionStore;

// ============================================================
// Константы безопасности
// ============================================================

const MCP_MAX_BODY_SIZE = 1048576; // 1 MB
const MCP_MAX_JSON_DEPTH = 100;
const MCP_ALLOWED_METHODS = 'POST, GET, DELETE, OPTIONS';
const MCP_ALLOWED_HEADERS = 'Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID';

// ============================================================
// Логирование
// ============================================================

$mcpLog = static function (string $message): void {
    $logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/mcp_endpoint.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
};

// ============================================================
// Безопасная обработка CORS
// ============================================================

function getCorsAllowedOrigins(): array
{
    $raw = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'cors_allowed_origins', '');
    if (empty($raw)) {
        return [];
    }
    $origins = array_map('trim', explode(',', $raw));
    $allowed = [];
    foreach ($origins as $origin) {
        if (!empty($origin)) {
            $allowed[] = $origin;
        }
    }
    return $allowed;
}

function isAllowedOrigin(string $origin): bool
{
    $allowed = getCorsAllowedOrigins();
    if (empty($allowed)) {
        return false;
    }
    foreach ($allowed as $allowedOrigin) {
        if ($allowedOrigin === $origin) {
            return true;
        }
    }
    return false;
}

function sendCorsHeaders(string $requestOrigin = ''): void
{
    header('Vary: Origin');
    if ($requestOrigin !== '' && isAllowedOrigin($requestOrigin)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: ' . MCP_ALLOWED_METHODS);
        header('Access-Control-Allow-Headers: ' . MCP_ALLOWED_HEADERS);
        header('Access-Control-Expose-Headers: Mcp-Session-Id');
        header('Access-Control-Max-Age: 86400');
    }
}

// ============================================================
// Валидация метода запроса
// ============================================================

function validateRequestMethod(string $method): bool
{
    $allowed = array_map('trim', explode(',', MCP_ALLOWED_METHODS));
    return in_array($method, $allowed, true);
}

// ============================================================
// Чтение тела запроса с ограничением размера
// ============================================================

function readRequestBody(): string
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > MCP_MAX_BODY_SIZE) {
        throw new \RuntimeException('Request body exceeds maximum allowed size');
    }
    $body = file_get_contents('php://input');
    if ($body === false) {
        throw new \RuntimeException('Failed to read request body');
    }
    if (strlen($body) > MCP_MAX_BODY_SIZE) {
        throw new \RuntimeException('Request body exceeds maximum allowed size');
    }
    return $body;
}

// ============================================================
// Валидация JSON
// ============================================================

function decodeRequestBody(string $body): array
{
    $decoded = json_decode($body, true, MCP_MAX_JSON_DEPTH);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
    }
    if (json_last_error() === JSON_ERROR_DEPTH) {
        throw new \RuntimeException('JSON exceeds maximum allowed depth');
    }
    if (!is_array($decoded)) {
        throw new \RuntimeException('Invalid JSON: expected object');
    }
    return $decoded;
}

// ============================================================
// Валидация метода MCP
// ============================================================

function validateMcpMethod(?string $method): void
{
    if (!is_string($method) || $method === '') {
        throw new \RuntimeException('Invalid Request: method is required');
    }
    $allowedMethods = [
        'initialize', 'initialized', 'notifications/initialized',
        'tools/list', 'tools/call', 'resources/list', 'resources/read', 'ping'
    ];
    // Допускаем любые строки, но проверяем базовые ограничения
    if (strlen($method) > 200) {
        throw new \RuntimeException('Invalid Request: method too long');
    }
}

// ============================================================
// Обработка Origin
// ============================================================

$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '') {
    sendCorsHeaders($requestOrigin);
} else {
    sendCorsHeaders('');
}

// ============================================================
// OPTIONS - CORS preflight (с ограниченными заголовками)
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Vary: Origin');
    if ($requestOrigin !== '' && isAllowedOrigin($requestOrigin)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
        header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID');
        header('Access-Control-Expose-Headers: Mcp-Session-Id');
    }
    http_response_code(204);
    exit;
}

// ============================================================
// Валидация HTTP метода
// ============================================================

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!validateRequestMethod($requestMethod)) {
    header('Vary: Origin');
    if ($requestOrigin !== '' && isAllowedOrigin($requestOrigin)) {
        header('Access-Control-Allow-Origin: ' . $requestOrigin);
    }
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode([
        'jsonrpc' => '2.0',
        'error' => ['code' => -32000, 'message' => 'Method not allowed'],
        'id' => null,
    ]);
    exit;
}

// ============================================================
// Авторизация (для всех методов кроме OPTIONS)
// ============================================================

$checkAuth = static function (): void {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (empty($authHeader)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32000, 'message' => 'Authorization required'],
            'id' => null,
        ]);
        exit;
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32000, 'message' => 'Invalid authorization format'],
            'id' => null,
        ]);
        exit;
    }

    $token = $matches[1];
    $expectedToken = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'auth_token', '');

    if (empty($expectedToken) || !hash_equals($expectedToken, $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32000, 'message' => 'Invalid token'],
            'id' => null,
        ]);
        exit;
    }
};

// ============================================================
// Создание MCP сервера
// ============================================================

$createMcpServer = static function (
    ?array $scopes = null,
    ?\Bitrix24\Mcp\Session\SessionStore $sessionStore = null
): \Bitrix24\Mcp\Server {
    $server = new \Bitrix24\Mcp\Server(true, false, $scopes, $sessionStore);

    $server->registerTool(new \Bitrix24\Mcp\Tools\DatabaseSchema());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmSchema());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmEntityList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmEntityGet());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmActivityList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CrmTimelineList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ModulesList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\CodeSearch());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ClassInspector());
    $server->registerTool(new \Bitrix24\Mcp\Tools\ComponentsList());
    $server->registerTool(new \Bitrix24\Mcp\Tools\EventHandlers());
    $server->registerTool(new \Bitrix24\Mcp\Tools\FileReader());
    $server->registerTool(new \Bitrix24\Mcp\Tools\SqlQuery());

    $server->registerResource(new \Bitrix24\Mcp\Resources\ProjectStructure());
    $server->registerResource(new \Bitrix24\Mcp\Resources\D7Documentation());
    $server->registerResource(new \Bitrix24\Mcp\Resources\CodingStandards());

    return $server;
};

// ============================================================
// GET - SSE стрим
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $checkAuth();

    $mcpLog('GET SSE request');

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    if (ob_get_level()) {
        ob_end_clean();
    }

    echo ": connected\n\n";
    flush();

    $timeout = 30;
    $start = time();
    while ((time() - $start) < $timeout) {
        echo ": ping\n\n";
        flush();
        if (connection_aborted()) {
            break;
        }
        sleep(5);
    }

    exit;
}

// ============================================================
// DELETE - завершение сессии
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $checkAuth();
    $mcpLog('DELETE session request');

    try {
        if (!\Bitrix\Main\Loader::includeModule('bitrix24.mcp')) {
            throw new \RuntimeException('Module bitrix24.mcp is not installed');
        }

        $useBitrixStore = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'use_bitrix_session_store', 'Y') === 'Y';
        $sessionStore = $useBitrixStore ? new BitrixOptionSessionStore() : new MemorySessionStore();

        $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
        if ($sessionId === null || $sessionId === '') {
            throw new \RuntimeException('Mcp-Session-Id required');
        }

        $tokenHash = hash('sha256', ($_SERVER['HTTP_X_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        $revoked = $sessionStore->revoke($sessionId, $tokenHash);

        if (!$revoked) {
            throw new \RuntimeException('Session not found');
        }

        sendCorsHeaders($requestOrigin);
        header('Content-Type: application/json');
        http_response_code(200);
        echo json_encode(['status' => 'session revoked']);
        exit;
    } catch (\Exception $e) {
        $mcpLog('DELETE request failed');
        sendCorsHeaders($requestOrigin);
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => -32603, 'message' => 'Revoke failed'],
            'id' => null,
        ]);
        exit;
    }
}

// ============================================================
// POST - основной MCP endpoint
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $checkAuth();

    try {
        if (!\Bitrix\Main\Loader::includeModule('bitrix24.mcp')) {
            throw new \RuntimeException('Module bitrix24.mcp is not installed');
        }

        $body = readRequestBody();

        $mcpLog('POST body received: ' . strlen($body) . ' bytes');

        $request = decodeRequestBody($body);

        if (!isset($request['method'])) {
            throw new \RuntimeException('Invalid Request: method required');
        }

        $method = $request['method'];
        validateMcpMethod($method);

        $mcpLog('MCP method: ' . $method);

        $isNotification = !isset($request['id']);
        $isInitialize = ($method === 'initialize');

        $useBitrixStore = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'use_bitrix_session_store', 'Y') === 'Y';
        $sessionStore = $useBitrixStore ? new BitrixOptionSessionStore() : new MemorySessionStore();

        $currentSessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;

        // Resolve scopes from safe Bitrix option auth_scopes
        // Resolve scopes from safe Bitrix option auth_scopes
        $authScopesRaw = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'auth_scopes', '');
        $scopes = ToolScopePolicy::resolveScopes($authScopesRaw);

        // Legacy compatibility: if legacy_auth_full_access is true and auth_scopes is empty,
        // provide only safe read scopes without sensitive ones
        $legacyFullAccess = \Bitrix\Main\Config\Option::get('bitrix24.mcp', 'legacy_auth_full_access', 'Y') === 'Y';
        if ($scopes === [] && $legacyFullAccess) {
            $scopes = ['tools:list', 'resources:read', 'crm:read', 'bizproc:read'];
        }

        // Session lifecycle integration
        $tokenHash = hash('sha256', ($_SERVER['HTTP_X_AUTHORIZATION'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? ''));

        if ($isInitialize) {
            $sessionId = $sessionStore->create($tokenHash);
            $currentSessionId = $sessionId;
            $mcpLog('New session initialized');
        } else {
            // Require session for non-initialize requests (except initialize notification)
            if ($currentSessionId === null || $currentSessionId === '') {
                throw new \RuntimeException('Mcp-Session-Id header is required');
            }
            $sessionData = $sessionStore->get($currentSessionId, $tokenHash);
            if ($sessionData === null) {
                throw new \RuntimeException('Invalid or expired session');
            }
            if ($method !== 'initialized' && $method !== 'notifications/initialized' && !$sessionData['initialized']) {
                throw new \RuntimeException('Server not initialized');
            }
            $sessionId = $currentSessionId;
        }

        $server = $createMcpServer($scopes, $sessionStore);
        $response = $server->handleRequest($request);

        $mcpLog('MCP response: ' . strlen(json_encode($response)) . ' bytes');

        $sessionIdForHeader = $currentSessionId ?? $sessionId ?? null;

        sendCorsHeaders($requestOrigin);

        if ($isNotification) {
            http_response_code(202);
            $mcpLog('Notification → 202 Accepted');
            exit;
        }

        http_response_code(200);
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');

        if ($sessionIdForHeader) {
            header('Mcp-Session-Id: ' . $sessionIdForHeader);
        }

        echo json_encode($response);
        $mcpLog('Response sent OK');
    } catch (\Exception $e) {
        $errorId = bin2hex(random_bytes(8));
        $mcpLog('ERROR [' . $errorId . ']: request failed');

        $requestId = isset($request) && is_array($request) ? ($request['id'] ?? null) : null;

        $errorResponse = [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32603,
                'message' => 'Internal server error',
                'data' => ['error_id' => $errorId],
            ],
            'id' => $requestId,
        ];

        sendCorsHeaders($requestOrigin);
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode($errorResponse);
    }

    exit;
}

// ============================================================
// Любой другой метод → 405
// ============================================================

sendCorsHeaders($requestOrigin);
http_response_code(405);
header('Content-Type: application/json');
echo json_encode([
    'jsonrpc' => '2.0',
    'error' => ['code' => -32000, 'message' => 'Method not allowed'],
    'id' => null,
]);
