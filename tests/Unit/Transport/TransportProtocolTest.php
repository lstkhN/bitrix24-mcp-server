<?php

namespace Bitrix24\Mcp\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Transport\AbstractTransport;
use Bitrix24\Mcp\Transport\HttpTransport;

final class TransportProtocolTest extends TestCase
{
    public function testJsonEncodingPreservesUnicodeAndSlashes(): void
    {
        $transport = new class extends AbstractTransport {
            public function receive(): ?array { return null; }
            public function send(array $response): void {}
            public function encode(array $value): string { return $this->encodeJson($value); }
            public function decode(string $value): ?array { return $this->decodeJson($value); }
        };

        $json = $transport->encode(['text' => 'Привет / MCP']);

        self::assertSame('{"text":"Привет / MCP"}', $json);
        self::assertSame(['text' => 'Привет / MCP'], $transport->decode($json));
    }

    public function testInvalidJsonHasActionableTransportError(): void
    {
        $transport = new class extends AbstractTransport {
            public function receive(): ?array { return null; }
            public function send(array $response): void {}
            public function decode(string $value): ?array { return $this->decodeJson($value); }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON decode error:');

        $transport->decode('{invalid');
    }

    public function testHttpSendCachesTheLastResponse(): void
    {
        $transport = new HttpTransport();
        $response = ['jsonrpc' => '2.0', 'result' => ['status' => 'pong'], 'id' => 7];

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $_SERVER['REQUEST_METHOD'] = 'POST';

        ob_start();
        try {
            $transport->send($response);
            $output = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        } finally {
            if ($requestMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $requestMethod;
            }
        }

        self::assertSame($response, $transport->getLastResponse());
        self::assertSame(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $output);
    }
}
