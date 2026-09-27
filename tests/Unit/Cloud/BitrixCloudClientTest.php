<?php

namespace Bitrix24\Mcp\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\BitrixCloudClient;
use Bitrix24\Mcp\Cloud\ConfigurationException;
use Bitrix24\Mcp\Cloud\HttpResponse;
use Bitrix24\Mcp\Cloud\HttpTransport;
use Bitrix24\Mcp\Cloud\RateLimitException;
use Bitrix24\Mcp\Cloud\WebhookCredential;

final class BitrixCloudClientTest extends TestCase
{
    public function testWebhookCallBuildsSafeUrlAndReturnsResult(): void
    {
        $transport = new class implements HttpTransport {
            public string $url = '';
            public string $body = '';
            public function post(string $url, array $headers, string $body, float $timeout): HttpResponse
            {
                $this->url = $url;
                $this->body = $body;
                return new HttpResponse(200, '{"result":{"ID":7}}');
            }
        };

        $client = new BitrixCloudClient(
            $transport,
            ['crm.deal.get'],
            sleeper: static function (): void {
            }
        );
        $result = $client->call(
            new WebhookCredential('tenant-a', 'https://example.bitrix24.ru', 1, 'secret'),
            'crm.deal.get',
            ['id' => 7]
        );

        self::assertSame(['ID' => 7], $result['result']);
        self::assertStringNotContainsString('secret', $transport->body);
        self::assertSame('https://example.bitrix24.ru/rest/1/secret/crm.deal.get.json', $transport->url);
    }

    public function testUnknownMethodsAreRejectedBeforeNetworkCall(): void
    {
        $transport = new class implements HttpTransport {
            public function post(string $url, array $headers, string $body, float $timeout): HttpResponse
            {
                self::fail('Network must not be called');
            }
        };

        $client = new BitrixCloudClient($transport, ['crm.deal.get']);
        $this->expectException(\Bitrix24\Mcp\Cloud\AuthorizationException::class);
        $client->call(new WebhookCredential('tenant-a', 'https://example.bitrix24.ru', 1, 'secret'), 'user.get');
    }

    public function testUnknownHostsAreRejected(): void
    {
        $transport = new class implements HttpTransport {
            public function post(string $url, array $headers, string $body, float $timeout): HttpResponse
            {
                self::fail('Network must not be called');
            }
        };

        $client = new BitrixCloudClient($transport, ['crm.deal.get']);
        $this->expectException(ConfigurationException::class);
        $client->call(new WebhookCredential('tenant-a', 'https://internal.example', 1, 'secret'), 'crm.deal.get');
    }

    public function testRateLimitRetriesThenFails(): void
    {
        $transport = new class implements HttpTransport {
            public int $calls = 0;
            public function post(string $url, array $headers, string $body, float $timeout): HttpResponse
            {
                $this->calls++;
                return new HttpResponse(429, '{}');
            }
        };

        $client = new BitrixCloudClient(
            $transport,
            ['crm.deal.get'],
            maxAttempts: 3,
            sleeper: static function (): void {
            }
        );
        $this->expectException(RateLimitException::class);
        try {
            $client->call(
                new WebhookCredential('tenant-a', 'https://example.bitrix24.ru', 1, 'secret'),
                'crm.deal.get'
            );
        } finally {
            self::assertSame(3, $transport->calls);
        }
    }
}
