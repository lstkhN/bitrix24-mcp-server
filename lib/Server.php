<?php

namespace Bitrix24\Mcp;

use Bitrix24\Mcp\Tools\AbstractTool;
use Bitrix24\Mcp\Resources\AbstractResource;
use Bitrix24\Mcp\Transport\AbstractTransport;
use Bitrix24\Mcp\Security\ToolScopePolicy;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Bitrix24\Mcp\Session\SessionStore;
use Bitrix\Main\Config\Option;

/**
 * Главный класс MCP сервера
 * Реализует JSON-RPC 2.0 протокол для Model Context Protocol
 */
class Server
{
    private const MCP_VERSION = '2024-11-05';
    private const SERVER_NAME = 'bitrix24-mcp-server';
    private const SERVER_VERSION = '1.0.0';

    /** @var AbstractTool[] */
    private array $tools = [];

    /** @var AbstractResource[] */
    private array $resources = [];

    private Logger $logger;
    private bool $initialized = false;
    private bool $legacyCompatibility = false;
    private bool $secureByDefault = true;
    private ?array $scopes = null;
    private ?ToolScopePolicy $scopePolicy = null;
    private ?SessionStore $sessionStore = null;

    public function __construct(
        bool $secureByDefault = true,
        bool $legacyCompatibility = false,
        ?array $scopes = null,
        ?SessionStore $sessionStore = null
    ) {
        $this->secureByDefault = $secureByDefault;
        $this->legacyCompatibility = $legacyCompatibility;
        $this->scopes = $scopes;
        $this->sessionStore = $sessionStore;
        if ($scopes !== null && $scopes !== []) {
            $this->scopePolicy = new ToolScopePolicy();
        }
        $this->initLogger();
    }

    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    public function isScopeEnabled(): bool
    {
        return $this->scopes !== null && $this->scopes !== [];
    }

    public function isLegacyCompatibility(): bool
    {
        return $this->legacyCompatibility;
    }

    public function isSecureByDefault(): bool
    {
        return $this->secureByDefault;
    }

    /**
     * Регистрация инструмента
     */
    public function registerTool(AbstractTool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
        $this->logger->info('Tool registered', [
            'tool' => $tool->getName(),
            'correlation_id' => $this->generateCorrelationId(),
        ]);
    }

    /**
     * Регистрация ресурса
     */
    public function registerResource(AbstractResource $resource): void
    {
        $this->resources[$resource->getUri()] = $resource;
        $this->logger->info('Resource registered', [
            'resource' => $resource->getUri(),
            'correlation_id' => $this->generateCorrelationId(),
        ]);
    }

    /**
     * Запуск MCP сервера с указанным транспортом
     */
    public function run(AbstractTransport $transport): void
    {
        $this->logger->info('MCP Server starting', [
            'transport_class' => get_class($transport),
            'transport_size_bytes' => strlen(get_class($transport)),
            'correlation_id' => $this->generateCorrelationId(),
        ]);

        try {
            while ($request = $transport->receive()) {
                $correlationId = $this->generateCorrelationId();
                $method = $request['method'] ?? 'unknown';
                $this->logger->info('Request received', [
                    'method' => $method,
                    'request_size_bytes' => strlen(json_encode($request)),
                    'correlation_id' => $correlationId,
                ]);

                $response = $this->handleRequest($request);

                if (!$this->isNotification($request)) {
                    $transport->send($response);
                    $this->logger->info('Response sent', [
                        'method' => $method,
                        'response_size_bytes' => strlen(json_encode($response)),
                        'correlation_id' => $correlationId,
                    ]);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Server error', [
                'message' => 'Internal server error occurred',
                'correlation_id' => $this->generateCorrelationId(),
            ]);
        }
    }

    /**
     * Обработка MCP запроса (JSON-RPC 2.0)
     */
    public function handleRequest(array $request): array
    {
        $correlationId = $this->generateCorrelationId();
        $method = $request['method'] ?? 'unknown';
        $requestSize = strlen(json_encode($request));

        $this->logger->debug('Request received', [
            'method' => $method,
            'request_size_bytes' => $requestSize,
            'correlation_id' => $correlationId,
        ]);

        $id = array_key_exists('id', $request) ? $request['id'] : null;

        if (($request['jsonrpc'] ?? null) !== '2.0') {
            return $this->createError(-32600, 'Invalid Request', $id);
        }

        if (!array_key_exists('method', $request) || !is_string($request['method']) || $request['method'] === '') {
            return $this->createError(-32600, 'Invalid Request: method required', $id);
        }

        if (
            array_key_exists('id', $request)
            && !is_int($request['id'])
            && !is_string($request['id'])
            && $request['id'] !== null
        ) {
            return $this->createError(-32600, 'Invalid Request: invalid id', null);
        }

        $method = $request['method'];
        $params = $request['params'] ?? [];
        if (!is_array($params)) {
            return $this->createError(-32602, 'Invalid params', $id);
        }

        // Scope authorization: deny-by-default when scopes are explicitly set
        if ($this->scopePolicy !== null) {
            $scopePolicy = $this->scopePolicy;
            $toolName = (is_string($params['name'] ?? null) ? $params['name'] : null);

            switch ($method) {
                case 'tools/list':
                    if (!$scopePolicy->isAllowed($method, null, $this->scopes ?? [])) {
                        return $this->createError(-32603, 'Access denied: missing scope for ' . $method, $id);
                    }
                    break;
                case 'tools/call':
                    if ($toolName === null) {
                        return $this->createError(-32602, 'Invalid params: tool name required', $id);
                    }
                    if (!$scopePolicy->isAllowed($method, $toolName, $this->scopes ?? [])) {
                        return $this->createError(-32603, 'Access denied: missing scope for tool ' . $toolName, $id);
                    }
                    break;
                case 'resources/list':
                case 'resources/read':
                    if (!$scopePolicy->isAllowed($method, null, $this->scopes ?? [])) {
                        return $this->createError(-32603, 'Access denied: missing scope for ' . $method, $id);
                    }
                    break;
            }
        }

        $requiresInitGate = in_array($method, [
            'tools/list', 'tools/call', 'resources/list', 'resources/read',
        ], true);

        // Session lifecycle validation (provider-neutral)
        $sessionStore = $this->sessionStore ?? null;
        $sessionInitialized = false;

        // Initialize releases session
        if ($method === 'initialize') {
            // Allow initialize regardless of existing session (new session released by caller)
        } else {
            // For all non-initialize requests, enforce session gate when session store is active.
            $isNotification = !array_key_exists('id', $request);
            if ($sessionStore !== null && !$this->legacyCompatibility) {
                // For methods requiring init gate, require session with initialized state
                $needsSessionCheck = $requiresInitGate
                    || ($method === 'initialized'
                        || $method === 'notifications/initialized');
                if ($needsSessionCheck) {
                    $sessionIdHeader = $request['session_id'] ?? ($_SERVER['HTTP_MCP_SESSION_ID'] ?? null);
                    if ($sessionIdHeader === null || $sessionIdHeader === '') {
                        return $this->createError(
                            -32603,
                            'Session required: initialize must be called before accessing server capabilities',
                            $id
                        );
                    }
                    $tokenHash = $this->getBoundTokenHash();
                    $sessionData = $sessionStore->get((string)$sessionIdHeader, $tokenHash);
                    if ($sessionData === null) {
                        return $this->createError(
                            -32603,
                            'Invalid or expired session',
                            $id
                        );
                    }
                    if ($requiresInitGate && !$sessionData['initialized']) {
                        return $this->createError(
                            -32603,
                            'Server not initialized: initialize must complete before using this method',
                            $id
                        );
                    }
                    $sessionInitialized = (bool)$sessionData['initialized'];
                }
            }
        }

        if ($requiresInitGate && !$this->legacyCompatibility && !$this->initialized && !$sessionInitialized) {
            return $this->createError(
                -32603,
                'Server not initialized',
                $id
            );
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'initialized', 'notifications/initialized' => $this->handleInitialized($params),
                'tools/list' => $this->listTools(),
                'tools/call' => $this->callTool($params),
                'resources/list' => $this->listResources(),
                'resources/read' => $this->readResource($params),
                'ping' => ['status' => 'pong'],
                default => throw new \BadMethodCallException("Method not found: $method"),
            };

            $response = $this->createSuccess($result, $id);
            $responseSize = strlen(json_encode($response));
            $this->logger->debug('Response sent', [
                'method' => $method,
                'response_size_bytes' => $responseSize,
                'correlation_id' => $correlationId,
            ]);

            return $response;
        } catch (\BadMethodCallException $e) {
            $this->logger->warning('Unknown MCP method', [
                'method' => $method,
                'correlation_id' => $correlationId,
            ]);
            return $this->createError(-32601, 'Method not found', $id);
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Invalid MCP parameters', [
                'method' => $method,
                'correlation_id' => $correlationId,
            ]);
            return $this->createError(-32602, 'Invalid params', $id);
        } catch (\Exception $e) {
            $this->logger->error('Request handling error', [
                'method' => $method,
                'message' => 'Internal server error occurred',
                'correlation_id' => $correlationId,
            ]);
            return $this->createError(-32603, 'Internal server error', $id);
        }
    }

    /**
     * Проверяет, является ли JSON-RPC запрос уведомлением.
     */
    private function isNotification(array $request): bool
    {
        return !array_key_exists('id', $request);
    }

    /**
     * Инициализация MCP сервера
     */
    private function initialize(array $params): array
    {
        $this->initialized = true;

        // If session store is configured, bind session initialization state
        $sessionStore = $this->sessionStore;
        if ($sessionStore !== null) {
            $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
            if ($sessionId !== null && $sessionId !== '') {
                $tokenHash = $this->getBoundTokenHash();
                $existing = $sessionStore->get($sessionId, $tokenHash);
                if ($existing !== null) {
                    $sessionStore->setInitialized($sessionId, $tokenHash);
                } else {
                    // Create new session on initialize if one isn't present
                    $newSessionId = $sessionStore->create($tokenHash);
                    $sessionStore->setInitialized($newSessionId, $tokenHash);
                    // Note: the new session ID should be returned by controller, not server
                }
            }
        }

        $this->logger->info('Server initialized', [
            'correlation_id' => $this->generateCorrelationId(),
        ]);

        return [
            'protocolVersion' => self::MCP_VERSION,
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
                'resources' => [
                    'subscribe' => false,
                    'listChanged' => false,
                ],
            ],
        ];
    }

    /**
     * Обработка уведомления initialized от клиента
     * Это notification (без id), поэтому возвращаем пустой результат
     */
    private function handleInitialized(array $params): array
    {
        if ($this->sessionStore !== null) {
            $sessionId = $_SERVER['HTTP_MCP_SESSION_ID'] ?? null;
            if ($sessionId !== null && $sessionId !== '') {
                $tokenHash = $this->getBoundTokenHash();
                $this->sessionStore->setInitialized($sessionId, $tokenHash);
            }
        }

        $this->logger->info('Client confirmed initialization', [
            'correlation_id' => $this->generateCorrelationId(),
        ]);
        return [];
    }

    /**
     * Получение списка инструментов
     */
    private function listTools(): array
    {
        $sourceTools = $this->scopePolicy !== null
            ? $this->scopePolicy->filterTools($this->tools, $this->scopes ?? [])
            : $this->tools;

        $tools = [];
        foreach ($sourceTools as $tool) {
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $tool->getInputSchema(),
            ];
        }

        return ['tools' => $tools];
    }

    /**
     * Вызов инструмента
     */
    private function callTool(array $params): array
    {
        if (!isset($params['name'])) {
            throw new \Exception('Tool name is required');
        }

        $name = $params['name'];
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$name])) {
            throw new \Exception("Tool not found: $name");
        }

        $tool = $this->tools[$name];

        $this->logger->info('Tool called', [
            'tool' => $name,
            'arguments_size_bytes' => strlen(json_encode($arguments)),
            'correlation_id' => $this->generateCorrelationId(),
        ]);

        $startTime = microtime(true);
        $result = $tool->execute($arguments);
        $executionTime = round((microtime(true) - $startTime) * 1000, 2);

        $this->logger->info('Tool executed', [
            'tool' => $name,
            'execution_time_ms' => $executionTime,
            'correlation_id' => $this->generateCorrelationId(),
        ]);

        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ],
            ],
        ];
    }

    /**
     * Получение списка ресурсов
     */
    private function listResources(): array
    {
        $resources = $this->scopePolicy !== null
            ? $this->scopePolicy->filterResources($this->resources, $this->scopes ?? [])
            : $this->resources;

        $result = [];
        foreach ($resources as $resource) {
            $result[] = [
                'uri' => $resource->getUri(),
                'name' => $resource->getName(),
                'description' => $resource->getDescription(),
                'mimeType' => $resource->getMimeType(),
            ];
        }

        return ['resources' => $result];
    }

    /**
     * Чтение ресурса
     */
    private function readResource(array $params): array
    {
        if (!isset($params['uri'])) {
            throw new \Exception('Resource URI is required');
        }

        $uri = $params['uri'];

        if (!isset($this->resources[$uri])) {
            throw new \Exception("Resource not found: $uri");
        }

        $resource = $this->resources[$uri];

        // Scope authorization for resource read
        if ($this->scopePolicy !== null) {
            if (!isset($this->scopePolicy->filterResources($this->resources, $this->scopes ?? [])[$uri])) {
                throw new \Exception('Access denied: resource read denied by scope policy');
            }
        }

        $this->logger->info('Resource read', [
            'uri' => $uri,
            'correlation_id' => $this->generateCorrelationId(),
        ]);

        $content = $resource->read($params);

        return [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => $resource->getMimeType(),
                    'text' => $content,
                ],
            ],
        ];
    }

    /**
     * Создание успешного ответа JSON-RPC 2.0
     */
    private function createSuccess(array $result, $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'result' => $result,
            'id' => $id,
        ];
    }

    /**
     * Создание ответа с ошибкой JSON-RPC 2.0
     */
    private function createError(int $code, string $message, $id): array
    {
        return [
            'jsonrpc' => '2.0',
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'id' => $id,
        ];
    }

    /**
     * Генерация корреляционного ID
     */
    private function getBoundTokenHash(): string
    {
        // In HTTP context, the auth token is validated by controller/transport.
        // We derive a binding hash from the validated token or from a module secret.
        // This avoids storing the token in session metadata.
        $xAuth = $_SERVER['HTTP_X_AUTHORIZATION'] ?? '';
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $redirectAuth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $rawToken = $xAuth ?: ($auth ?: $redirectAuth);
        if (preg_match('/^Bearer\s+(.+)$/i', $rawToken, $matches)) {
            return hash('sha256', $matches[1]);
        }
        // Fallback to module option-derived binding when header is missing (tests / stdio)
        $fallback = Option::get('bitrix24.mcp', 'auth_token', '');
        return hash('sha256', $fallback . '|mcp-server');
    }

    private function generateCorrelationId(): string
    {
        return substr(bin2hex(random_bytes(8)), 0, 16);
    }

    /**
     * Инициализация логгера
     */
    private function initLogger(): void
    {
        $logEnabled = Option::get('bitrix24.mcp', 'log_enabled', 'Y') === 'Y';

        $this->logger = new Logger('Bitrix24-mcp');

        if ($logEnabled) {
            $logPath = ($_SERVER['DOCUMENT_ROOT'] ?? '/tmp') . '/local/logs/mcp.log';
            $logDir = dirname($logPath);

            if (!is_dir($logDir)) {
                @mkdir($logDir, 0775, true);
            }

            if (is_dir($logDir) && is_writable($logDir)) {
                try {
                    $handler = new StreamHandler($logPath, Logger::INFO);
                    $handler->setFormatter(new \Monolog\Formatter\LineFormatter(
                        "[%datetime%] %channel%.%level_name%: %message% %context%\n",
                        'Y-m-d H:i:s.u',
                        true,
                        true
                    ));
                    $this->logger->pushHandler($handler);
                } catch (\Exception $e) {
                    error_log('MCP: Failed to initialize file logger');
                }
            } else {
                error_log('MCP: Log directory is not writable');
            }
        }
    }
}
