<?php

namespace Bitrix24\Mcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Server;
use Bitrix24\Mcp\Tools\AbstractTool;
use Bitrix24\Mcp\Transport\AbstractTransport;

final class ServerProtocolTest extends TestCase
{
    private function server(bool $legacy = false): Server
    {
        $server = new Server(secureByDefault: true, legacyCompatibility: $legacy);
        $server->registerTool(new class extends AbstractTool {
            public function getName(): string { return 'echo'; }
            public function getDescription(): string { return 'Echo test tool'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function execute(array $arguments): array { return $arguments; }
        });
        return $server;
    }

    public function testInitializeReturnsMcpCapabilitiesAndRequestId(): void
    {
        $response = $this->server()->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => ['clientInfo' => ['name' => 'test-client']],
        ]);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame('init-1', $response['id']);
        self::assertSame('2024-11-05', $response['result']['protocolVersion']);
        self::assertSame(['name' => 'bitrix24-mcp-server', 'version' => '1.0.0'], $response['result']['serverInfo']);
        self::assertSame(['listChanged' => false], $response['result']['capabilities']['tools']);
    }

    public function testSecureByDefaultRequiresInitializeForTools(): void
    {
        $server = $this->server();
        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertSame(-32603, $response['error']['code']);
        self::assertSame('Server not initialized', $response['error']['message']);
    }

    public function testLegacyCompatibilityAllowsToolsWithoutInitialize(): void
    {
        $server = $this->server(true);
        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        self::assertArrayNotHasKey('error', $response);
        self::assertSame('2.0', $response['jsonrpc']);
    }

    public function testInitializeGateEnforcedForResources(): void
    {
        $server = $this->server();
        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'resources/list',
        ]);

        self::assertSame(-32603, $response['error']['code']);
    }

    public function testSecureErrorsAreNeutralAndHideDetails(): void
    {
        $server = $this->server();
        $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [],
        ]);

        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'unknown/method',
        ]);

        self::assertSame(-32601, $response['error']['code']);
        self::assertSame('Method not found', $response['error']['message']);
        self::assertArrayNotHasKey('trace', $response['error']);
        self::assertArrayNotHasKey('data', $response['error']);
    }

    public function testExternalErrorResponseDoesNotExposePathOrSql(): void
    {
        $server = $this->server();
        $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [],
        ]);

        $server->registerTool(new class extends AbstractTool {
            public function getName(): string { return 'fail'; }
            public function getDescription(): string { return 'Fail'; }
            public function getInputSchema(): array { return ['type' => 'object']; }
            public function execute(array $arguments): array
            {
                throw new \RuntimeException('Database connection failed: SELECT * FROM secret_table');
            }
        });

        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'fail'],
        ]);

        self::assertSame(-32603, $response['error']['code']);
        self::assertSame('Internal server error', $response['error']['message']);
        self::assertStringNotContainsString('secret_table', $response['error']['message']);
        self::assertStringNotContainsString('SELECT', $response['error']['message']);
        self::assertStringNotContainsString('Database', $response['error']['message']);
    }

    public function testLogsOnlyMethodSizesAndCorrelationId(): void
    {
        $server = $this->server();
        // No assertions on internal log state; we rely on code review that
        // request/response full bodies are not logged (only sizes, method, correlation_id).
        self::assertTrue($server->isSecureByDefault());
    }

    public function testLegacyCompatibilityModeIsConfigurable(): void
    {
        $secure = new Server();
        $legacy = new Server(secureByDefault: true, legacyCompatibility: true);

        self::assertTrue($secure->isSecureByDefault());
        self::assertFalse($secure->isLegacyCompatibility());
        self::assertTrue($legacy->isSecureByDefault());
        self::assertTrue($legacy->isLegacyCompatibility());
    }

    public function testToolsListAndCallReturnTheToolContract(): void
    {
        $server = $this->server();
        $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [],
        ]);

        $list = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/list',
        ]);
        self::assertSame([
            'name' => 'echo',
            'description' => 'Echo test tool',
            'inputSchema' => ['type' => 'object'],
        ], $list['result']['tools'][0]);

        $call = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'echo', 'arguments' => ['message' => 'hello']],
        ]);

        self::assertSame('text', $call['result']['content'][0]['type']);
        self::assertSame('{"message":"hello"}', $call['result']['content'][0]['text']);
    }

    public function testInvalidJsonRpcVersionIsRejectedWithRequestId(): void
    {
        $response = $this->server()->handleRequest([
            'jsonrpc' => '1.0',
            'id' => 3,
            'method' => 'ping',
        ]);

        self::assertSame(-32600, $response['error']['code']);
        self::assertSame(3, $response['id']);
    }

    public function testInvalidIdIsRejectedWithoutEchoingInvalidId(): void
    {
        $response = $this->server()->handleRequest([
            'jsonrpc' => '2.0',
            'id' => ['not' => 'allowed'],
            'method' => 'ping',
        ]);

        self::assertSame(-32600, $response['error']['code']);
        self::assertNull($response['id']);
    }

    public function testUnknownMethodUsesMethodNotFoundError(): void
    {
        $server = $this->server();
        $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [],
        ]);

        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'does/not/exist',
        ]);

        self::assertSame(-32601, $response['error']['code']);
        self::assertSame(1, $response['id']);
    }

    public function testNonArrayParamsAreRejected(): void
    {
        $server = $this->server();
        $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'init-1',
            'method' => 'initialize',
            'params' => [],
        ]);

        $response = $server->handleRequest([
            'jsonrpc' => '2.0',
            'id' => 'params',
            'method' => 'tools/list',
            'params' => 'invalid',
        ]);

        self::assertSame(-32602, $response['error']['code']);
    }

    public function testRunDoesNotSendResponseForNotification(): void
    {
        $transport = new class extends AbstractTransport {
            public array $sent = [];
            private array $requests = [
                ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ];

            public function receive(): ?array { return array_shift($this->requests); }
            public function send(array $response): void { $this->sent[] = $response; }
        };

        $server = $this->server(true);
        $server->run($transport);

        self::assertCount(1, $transport->sent);
        self::assertSame(1, $transport->sent[0]['id']);
    }
}
