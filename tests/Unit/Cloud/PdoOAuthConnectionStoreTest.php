<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\OAuthConnection;
use Bitrix24\Mcp\Cloud\OAuthConnectionTokens;
use Bitrix24\Mcp\Cloud\PdoOAuthConnectionStore;
use Bitrix24\Mcp\Cloud\SodiumTokenEncryptor;

final class PdoOAuthConnectionStoreTest extends TestCase
{
    private PdoOAuthConnectionStore $store;
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(
                'PDO SQLite driver is unavailable; PdoOAuthConnectionStore tests require sqlite::memory:.'
            );
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec(
            'CREATE TABLE oauth_connections ('
            . 'tenant_id VARCHAR(255) NOT NULL, subject_id VARCHAR(255) NOT NULL, connection_id VARCHAR(255) NOT NULL, '
            . 'scopes_json TEXT NOT NULL, status VARCHAR(32) NOT NULL, expires_at BIGINT NULL, '
            . 'version BIGINT NOT NULL, '
            . 'access_token_ciphertext TEXT NOT NULL, refresh_token_ciphertext TEXT NULL, created_at BIGINT NOT NULL, '
            . 'updated_at BIGINT NOT NULL, revoked_at BIGINT NULL, PRIMARY KEY (tenant_id, subject_id, connection_id))'
        );
        $this->store = new PdoOAuthConnectionStore(
            $this->pdo,
            new SodiumTokenEncryptor('test', ['test' => str_repeat('k', 32)]),
        );
    }

    public function testStoresCiphertextAndMetadataDoesNotExposeTokens(): void
    {
        $connection = $this->create();

        $row = $this->pdo
            ->query('SELECT access_token_ciphertext, refresh_token_ciphertext FROM oauth_connections')
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertNotSame('access-secret', $row['access_token_ciphertext']);
        self::assertNotSame('refresh-secret', $row['refresh_token_ciphertext']);
        self::assertStringStartsWith('v1.test.', $row['access_token_ciphertext']);
        self::assertSame(['crm.read'], $connection->scopes());
        self::assertFalse(property_exists($connection, 'accessToken'));
        self::assertFalse(property_exists($connection, 'refreshToken'));
    }

    public function testCrossTenantAndSubjectAreDenied(): void
    {
        $this->create();

        self::assertNull($this->store->getConnection('other-tenant', 'subject-a', 'connection-a'));
        self::assertNull($this->store->getActiveTokens('tenant-a', 'other-subject', 'connection-a'));
        self::assertFalse($this->store->compareAndSwapTokens(
            'other-tenant',
            'subject-a',
            'connection-a',
            0,
            new OAuthConnectionTokens('changed-access', 'changed-refresh'),
            200,
        ));
        self::assertFalse($this->store->revokeConnection('other-tenant', 'subject-a', 'connection-a', 300));
        self::assertFalse($this->store->deleteConnection('tenant-a', 'other-subject', 'connection-a'));
        self::assertNotNull($this->store->getActiveTokens('tenant-a', 'subject-a', 'connection-a'));
    }

    public function testCompareAndSwapRejectsConflictAndRotatesTokens(): void
    {
        $this->create();

        self::assertTrue($this->store->compareAndSwapTokens(
            'tenant-a',
            'subject-a',
            'connection-a',
            0,
            new OAuthConnectionTokens('rotated-access', 'rotated-refresh'),
            999,
        ));
        self::assertFalse($this->store->compareAndSwapTokens(
            'tenant-a',
            'subject-a',
            'connection-a',
            0,
            new OAuthConnectionTokens('stale-access', 'stale-refresh'),
            1000,
        ));
        $tokens = $this->store->getActiveTokens('tenant-a', 'subject-a', 'connection-a');
        $connection = $this->store->getConnection('tenant-a', 'subject-a', 'connection-a');

        self::assertNotNull($tokens);
        self::assertSame('rotated-access', $tokens->accessToken());
        self::assertSame('rotated-refresh', $tokens->refreshToken());
        self::assertNotNull($connection);
        self::assertSame(1, $connection->version());
        self::assertSame(999, $connection->expiresAt());
    }

    public function testRevokeFailsClosed(): void
    {
        $this->create();

        self::assertTrue($this->store->revokeConnection('tenant-a', 'subject-a', 'connection-a', 500));
        self::assertNull($this->store->getActiveTokens('tenant-a', 'subject-a', 'connection-a'));
        self::assertFalse($this->store->compareAndSwapTokens(
            'tenant-a',
            'subject-a',
            'connection-a',
            1,
            new OAuthConnectionTokens('new-access', 'new-refresh'),
            999,
        ));
        self::assertSame(
            OAuthConnection::STATUS_REVOKED,
            $this->store->getConnection('tenant-a', 'subject-a', 'connection-a')?->status()
        );
    }

    public function testDeleteRemovesConnectionAndTokens(): void
    {
        $this->create();

        self::assertTrue($this->store->deleteConnection('tenant-a', 'subject-a', 'connection-a'));
        self::assertNull($this->store->getConnection('tenant-a', 'subject-a', 'connection-a'));
        self::assertNull($this->store->getActiveTokens('tenant-a', 'subject-a', 'connection-a'));
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM oauth_connections')->fetchColumn());
    }

    private function create(): OAuthConnection
    {
        return $this->store->createConnection(
            new OAuthConnection('tenant-a', 'subject-a', 'connection-a', ['crm.read'], 100),
            new OAuthConnectionTokens('access-secret', 'refresh-secret'),
        );
    }
}
