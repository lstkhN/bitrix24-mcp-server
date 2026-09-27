<?php

namespace Bitrix24\Mcp\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Error;
use Bitrix\Main\Config\Option;
use Bitrix24\Mcp\Server;
use Bitrix24\Mcp\Security\ToolScopePolicy;
use Bitrix24\Mcp\Session\SessionStore;
use Bitrix24\Mcp\Session\MemorySessionStore;
use Bitrix24\Mcp\Session\BitrixOptionSessionStore;

/**
 * MCP Streamable HTTP контроллер для Битрикс24
 *
 * Реализует MCP Streamable HTTP транспорт (спецификация 2025-03-26)
 * Поддерживает POST, GET, DELETE методы
 *
 * POST /api/mcp/ -> handleAction() - основной MCP endpoint
 * GET  /api/mcp/ -> sseAction()    - SSE стрим (опционально)
 * DELETE /api/mcp/ -> deleteAction() - завершение сессии
 */
class ApiController extends Controller
{
    /** @var array<string, bool> Активные сессии (в production - хранить в БД/кэше) */
    private static array $sessions = [];

    private ?SessionStore $sessionStore = null;

    /**
     * Настройка действий контроллера
     */
    public function configureActions(): array
    {
        return [
            'handle' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod([
                        ActionFilter\HttpMethod::METHOD_POST,
                    ]),
                ],
                '-prefilters' => [
                    ActionFilter\Authentication::class,
                    ActionFilter\Csrf::class,
                ],
            ],
            'sse' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod([
                        ActionFilter\HttpMethod::METHOD_GET,
                    ]),
                ],
                '-prefilters' => [
                    ActionFilter\Authentication::class,
                    ActionFilter\Csrf::class,
                ],
            ],
            'delete' => [
                'prefilters' => [
                    new ActionFilter\HttpMethod([
                        ActionFilter\HttpMethod::METHOD_DELETE,
                    ]),
                ],
                '-prefilters' => [
                    ActionFilter\Authentication::class,
                    ActionFilter\Csrf::class,
                ],
            ],
        ];
    }

    /**
     * Переопределяем фильтры по умолчанию - отключаем CSRF и Auth
     */
    public function getDefaultPreFilters(): array
    {
        return [];
    }

    /**
     * POST /api/mcp/ - основной MCP Streamable HTTP endpoint
     */
    public function handleAction(): void
    {
        // Выходим из Bitrix Controller flow - отдаём ответ напрямую
        $this->sendMcpResponse();
        // Не возвращаем ничего, мы уже отправили ответ
    }

    /**
     * GET /api/mcp/ - SSE стрим (опционально)
     */
    public function sseAction(): void
    {
        // Мы не поддерживаем GET SSE, возвращаем 405
        http_response_code(405);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => 'SSE stream not supported. Use POST.',
            ],
            'id' => null,
        ]);
        \Bitrix\Main\Application::getInstance()->terminate();
    }

    /**
     * DELETE /api/mcp/ - завершение сессии
     */
    public function deleteAction(): void
    {
        try {
            // Проверяем авторизацию
            $this->checkAuthorization();

            if (!Loader::includeModule('bitrix24.mcp')) {
                throw new \RuntimeException('Module bitrix24.mcp is not installed');
            }

            $useBitrixStore = Option::get('bitrix24.mcp', 'use_bitrix_session_store', 'Y') === 'Y';
            $sessionStore = $useBitrixStore ? new BitrixOptionSessionStore() : new MemorySessionStore();

            $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
            if ($sessionId === null || $sessionId === '') {
                throw new \RuntimeException('Mcp-Session-Id header is required for session revocation');
            }

            $tokenHash = $this->getBindingTokenHash();
            $revoked = $sessionStore->revoke($sessionId, $tokenHash);

            if (!$revoked) {
                throw new \RuntimeException('Session not found or already revoked');
            }

            $this->log('Session revoked');

            header('Content-Type: application/json');
            header('Cache-Control: no-cache');
            echo json_encode([
                'status' => 'session revoked',
            ]);
        } catch (\Throwable $e) {
            $this->log('DELETE request failed [correlation=' . $this->requestTraceId . ']');
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32603,
                    'message' => 'Revoke failed',
                ],
                'id' => null,
            ]);
        }
    }

    /**
     * Обработка MCP запроса и отправка ответа напрямую
     */
    private function sendMcpResponse(): void
    {
        $requestId = null;

        try {
            // Закрываем PHP сессию чтобы не блокировать другие запросы
            // MCP stateless - сессия не нужна
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            // Проверяем Bearer токен авторизации
            $this->checkAuthorization();

            // Загружаем модуль
            if (!Loader::includeModule('bitrix24.mcp')) {
                throw new \RuntimeException('Module bitrix24.mcp is not installed');
            }

            // Читаем тело запроса
            $body = file_get_contents('php://input');
            // Логируем только размер тела - содержимое может содержать чувствительные данные
            $this->log('Request body received: ' . strlen($body) . ' bytes');

            if (empty($body)) {
                throw new \RuntimeException('Request body is empty');
            }

            $request = json_decode($body, true);

            if ($request === null) {
                throw new \RuntimeException('Invalid JSON: ' . json_last_error_msg());
            }

            // Сохраняем ID для ответа об ошибке
            $requestId = $request['id'] ?? null;

            $this->log('MCP method: ' . ($request['method'] ?? 'unknown'));

            // Проверяем это notification (нет id) или request (есть id)
            $isNotification = !isset($request['id']);

            // Resolve scopes from safe Bitrix option auth_scopes
            $authScopesRaw = Option::get('bitrix24.mcp', 'auth_scopes', '');
            $scopes = ToolScopePolicy::resolveScopes($authScopesRaw);

            // Legacy compatibility: if legacy_auth_full_access is true and auth_scopes is empty,
            // provide only safe read scopes without sensitive ones
            $legacyFullAccess = Option::get('bitrix24.mcp', 'legacy_auth_full_access', 'Y') === 'Y';
            if ($scopes === [] && $legacyFullAccess) {
                $scopes = ['tools:list', 'resources:read', 'crm:read', 'bizproc:read'];
            }

            // Создаём или получаем session store в зависимости от окружения
            $useBitrixStore = Option::get('bitrix24.mcp', 'use_bitrix_session_store', 'Y') === 'Y';
            $this->sessionStore = $useBitrixStore ? new BitrixOptionSessionStore() : new MemorySessionStore();

            // Создаём MCP сервер с опциональными scopes
            $server = $this->createServer($scopes, $this->sessionStore);
            $response = $server->handleRequest($request);

            $this->log('MCP response size: ' . strlen(json_encode($response)) . ' bytes');

            // Если это notification (например initialized) - отвечаем 202
            if ($isNotification) {
                http_response_code(202);
                $this->log('Notification -> 202 Accepted');
                $this->terminate();
                return;
            }

            // Генерируем/проверяем Session ID
            $isInitialize = ($request['method'] ?? '') === 'initialize';

            if ($isInitialize && $this->sessionStore !== null) {
                // Генерируем новый Session ID и создаём сессию с привязкой к токену
                $token = $this->getBindingTokenHash();
                $newSessionId = $this->sessionStore->create($token);
                $sessionId = $newSessionId;
                $this->log('New session initialized');
            } elseif ($this->sessionStore !== null) {
                $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
                if ($sessionId === null || $sessionId === '') {
                    throw new \RuntimeException('Mcp-Session-Id header is required for non-initialize requests');
                }
                $token = $this->getBindingTokenHash();
                $sessionData = $this->sessionStore->get($sessionId, $token);
                if ($sessionData === null) {
                    throw new \RuntimeException('Invalid or expired session');
                }
                if (!$sessionData['initialized']) {
                    throw new \RuntimeException('Server not initialized: session exists but initialize not completed');
                }
            } else {
                $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
            }

            // Отправляем ответ
            http_response_code(200);
            header('Content-Type: application/json');
            header('Cache-Control: no-cache');

            if ($sessionId) {
                header('Mcp-Session-Id: ' . $sessionId);
                // Не логируем session ID или токен
                $this->log('Response includes session header');
            }

            // Без JSON_PRETTY_PRINT для уменьшения размера
            echo json_encode($response, JSON_UNESCAPED_UNICODE);

            $this->log('Response sent OK');
            $this->terminate();
        } catch (\Throwable $e) {
            $errorId = bin2hex(random_bytes(8));
            $correlationTag = '[correlation=' . $this->requestTraceId . ']';
            $this->log('ERROR [' . $errorId . ']: request failed ' . $correlationTag);


            $errorResponse = [
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32603,
                    'message' => 'Internal server error',
                    'data' => ['error_id' => $errorId],
                ],
                'id' => $requestId,
            ];

            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
            $this->terminate();
        }
    }

    /**
     * Безопасное завершение с выходом из Bitrix flow
     */
    private function terminate(): void
    {
        try {
            \Bitrix\Main\Application::getInstance()->terminate();
        } catch (\Throwable $e) {
            // Если terminate() не сработал, выходим напрямую
            exit;
        }
    }

    /**
     * Проверка Bearer токена
     *
     * Поддерживает два заголовка:
     * - X-Authorization: Bearer <token> (приоритетный, не вырезается nginx)
     * - Authorization: Bearer <token> (стандартный, fallback)
     */
    private function checkAuthorization(): void
    {
        // Приоритет: X-Authorization (кастомный, nginx не вырезает)
        // Fallback: Authorization (стандартный)
        $authHeader = $_SERVER['HTTP_X_AUTHORIZATION']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        // Попробовать через Bitrix Context
        if (empty($authHeader)) {
            $request = Context::getCurrent()->getRequest();
            $authHeader = $request->getHeader('X-Authorization')
                ?? $request->getHeader('Authorization')
                ?? '';
        }

        // Не логируем заголовки целиком - они содержат токен авторизации
        $this->log('Auth: X-Authorization=' . (!empty($_SERVER['HTTP_X_AUTHORIZATION']) ? 'present' : 'missing')
            . ', Authorization=' . (!empty($_SERVER['HTTP_AUTHORIZATION']) ? 'present' : 'missing')
            . ', resolved=' . (empty($authHeader) ? 'empty' : 'found'));

        if (empty($authHeader)) {
            http_response_code(401);
            throw new \RuntimeException(
                'Authorization header is required. Use X-Authorization or Authorization header.'
            );
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            http_response_code(401);
            throw new \RuntimeException('Invalid authorization format');
        }

        $token = $matches[1];
        $expectedToken = Option::get('bitrix24.mcp', 'auth_token', '');

        if (empty($expectedToken) || !hash_equals($expectedToken, $token)) {
            http_response_code(403);
            throw new \RuntimeException('Invalid authorization token');
        }
    }

    /**
     * Создание и настройка MCP сервера
     */
    private function getBindingTokenHash(): string
    {
        $authHeader = $_SERVER['HTTP_X_AUTHORIZATION']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return hash('sha256', $matches[1]);
        }
        return hash('sha256', Option::get('bitrix24.mcp', 'auth_token', '') . '|mcp-controller');
    }

    private function createServer(?array $scopes = null, ?SessionStore $sessionStore = null): Server
    {
        $server = new Server(true, false, $scopes, $sessionStore);

        // Регистрация инструментов
        $server->registerTool(new \Bitrix24\Mcp\Tools\DatabaseSchema());
        $server->registerTool(new \Bitrix24\Mcp\Tools\CrmSchema());
        $server->registerTool(new \Bitrix24\Mcp\Tools\ModulesList());
        $server->registerTool(new \Bitrix24\Mcp\Tools\CodeSearch());
        $server->registerTool(new \Bitrix24\Mcp\Tools\ClassInspector());
        $server->registerTool(new \Bitrix24\Mcp\Tools\ComponentsList());
        $server->registerTool(new \Bitrix24\Mcp\Tools\EventHandlers());
        $server->registerTool(new \Bitrix24\Mcp\Tools\FileReader());
        $server->registerTool(new \Bitrix24\Mcp\Tools\SqlQuery());
        $server->registerTool(new \Bitrix24\Mcp\Tools\BizprocReader());

        // Регистрация ресурсов
        $server->registerResource(new \Bitrix24\Mcp\Resources\ProjectStructure());
        $server->registerResource(new \Bitrix24\Mcp\Resources\D7Documentation());
        $server->registerResource(new \Bitrix24\Mcp\Resources\CodingStandards());

        return $server;
    }

    /** @var string Уникальный ID запроса для трассировки */
    private string $requestTraceId = '';

    /**
     * Логирование в файл с уникальным ID запроса
     */
    private function log(string $message): void
    {
        // Генерируем trace ID при первом вызове
        if (empty($this->requestTraceId)) {
            $this->requestTraceId = substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/mcp_controller.log';
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0775, true);
        }

        $timestamp = date('Y-m-d H:i:s.') . substr(microtime(), 2, 3);
        $traceId = $this->requestTraceId;

        // LOCK_EX убран для избежания блокировок при concurrent запросах
        @file_put_contents(
            $logFile,
            "[$timestamp][$traceId] $message\n",
            FILE_APPEND
        );
    }
}
