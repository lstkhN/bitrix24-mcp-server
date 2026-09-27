<?php

namespace Bitrix24\Mcp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\BitrixCloudClient;
use Bitrix24\Mcp\Cloud\CurlHttpTransport;
use Bitrix24\Mcp\Cloud\WebhookCredential;

final class CloudWebhookReadTest extends TestCase
{
    public function testWebhookCanReadCurrentProfile(): void
    {
        $this->requireIntegrationEnabled();

        $portalUrl = $this->requireEnvironment('BITRIX24_CLOUD_PORTAL_URL');
        $userId = filter_var($this->requireEnvironment('BITRIX24_CLOUD_WEBHOOK_USER_ID'), FILTER_VALIDATE_INT);
        self::assertIsInt($userId, 'BITRIX24_CLOUD_WEBHOOK_USER_ID must be a positive integer');
        self::assertGreaterThan(0, $userId, 'BITRIX24_CLOUD_WEBHOOK_USER_ID must be a positive integer');

        $client = new BitrixCloudClient(new CurlHttpTransport(), ['profile']);
        $response = $client->call(
            new WebhookCredential(
                getenv('BITRIX24_CLOUD_TENANT') ?: 'integration-cloud',
                $portalUrl,
                $userId,
                $this->requireEnvironment('BITRIX24_CLOUD_WEBHOOK_CODE')
            ),
            'profile'
        );

        self::assertSame('profile', $response['method']);
        self::assertSame(200, $response['status']);
        self::assertIsArray($response['result']);
    }

    private function requireIntegrationEnabled(): void
    {
        if (getenv('BITRIX24_RUN_INTEGRATION') !== '1') {
            self::markTestSkipped('Set BITRIX24_RUN_INTEGRATION=1 to permit live integration tests.');
        }
    }

    private function requireEnvironment(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::markTestSkipped(sprintf('Set %s to run this Cloud integration test.', $name));
        }

        return $value;
    }
}
