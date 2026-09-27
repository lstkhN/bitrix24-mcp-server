<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\OAuthAuthorizationAttempt;
use Bitrix24\Mcp\Cloud\OAuthAuthorizationAttemptRejectedException;
use Bitrix24\Mcp\Cloud\PdoOAuthAuthorizationAttemptStore;
use Bitrix24\Mcp\Cloud\SodiumTokenEncryptor;

final class PdoOAuthAuthorizationAttemptStoreTest extends TestCase
{
    private PdoOAuthAuthorizationAttemptStore $store;
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(
                'PDO SQLite driver is unavailable; authorization attempt tests require sqlite::memory:.',
            );
        }

        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec(
            'CREATE TABLE oauth_authorization_attempts ('
            . 'attempt_id VARCHAR(255) NOT NULL PRIMARY KEY, state_hash CHAR(64) NOT NULL, '
            . 'pkce_verifier_ciphertext TEXT NOT NULL, tenant_id VARCHAR(255) NOT NULL, '
            . 'subject_id VARCHAR(255) NOT NULL, session_binding VARCHAR(255) NOT NULL, '
            . 'redirect_uri VARCHAR(2048) NOT NULL, scopes_json TEXT NOT NULL, '
            . 'created_at BIGINT NOT NULL, expires_at BIGINT NOT NULL, consumed_at BIGINT NULL)'
        );
        $this->store = new PdoOAuthAuthorizationAttemptStore(
            $this->pdo,
            new SodiumTokenEncryptor('test', ['test' => str_repeat('k', 32)]),
        );
    }

    public function testStoresOnlyStateHashAndEncryptedVerifierAndConsumesOnce(): void
    {
        $state = 'opaque-state-value';
        $this->store->create($this->attempt($state));

        $row = $this->pdo
            ->query('SELECT state_hash, pkce_verifier_ciphertext, consumed_at FROM oauth_authorization_attempts')
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(OAuthAuthorizationAttempt::hashState($state), $row['state_hash']);
        self::assertNotSame($state, $row['state_hash']);
        self::assertNotSame('pkce-verifier-secret', $row['pkce_verifier_ciphertext']);
        self::assertStringStartsWith('v1.test.', $row['pkce_verifier_ciphertext']);

        $consumed = $this->store->consume('attempt-a', $state, 'tenant-a', 'subject-a', 'session-a', 110);
        self::assertSame('pkce-verifier-secret', $consumed->pkceVerifier());
        self::assertSame('https://app.example.test/oauth/callback', $consumed->redirectUri());
        self::assertSame(['crm.read'], $consumed->scopes());
        self::assertSame(110, $consumed->consumedAt());

        $this->expectException(OAuthAuthorizationAttemptRejectedException::class);
        $this->store->consume('attempt-a', $state, 'tenant-a', 'subject-a', 'session-a', 111);
    }

    public function testConsumeRejectsWrongStateAndAllBindings(): void
    {
        $this->store->create($this->attempt('correct-state'));

        foreach (
            [
            ['attempt-a', 'wrong-state', 'tenant-a', 'subject-a', 'session-a'],
            ['attempt-a', 'correct-state', 'other-tenant', 'subject-a', 'session-a'],
            ['attempt-a', 'correct-state', 'tenant-a', 'other-subject', 'session-a'],
            ['attempt-a', 'correct-state', 'tenant-a', 'subject-a', 'other-session'],
            ] as [$attemptId, $state, $tenantId, $subjectId, $sessionBinding]
        ) {
            try {
                $this->store->consume($attemptId, $state, $tenantId, $subjectId, $sessionBinding, 110);
                self::fail('A mismatched authorization attempt was consumed.');
            } catch (OAuthAuthorizationAttemptRejectedException) {
                self::assertTrue(true);
            }
        }

        $consumed = $this->pdo
            ->query('SELECT consumed_at IS NOT NULL FROM oauth_authorization_attempts')
            ->fetchColumn();
        self::assertSame(0, (int) $consumed);
    }

    public function testConsumeRejectsExpiredAttempt(): void
    {
        $this->store->create($this->attempt('state', expiresAt: 105));

        $this->expectException(OAuthAuthorizationAttemptRejectedException::class);
        $this->store->consume('attempt-a', 'state', 'tenant-a', 'subject-a', 'session-a', 105);
    }

    private function attempt(string $state, int $expiresAt = 200): OAuthAuthorizationAttempt
    {
        return new OAuthAuthorizationAttempt(
            'attempt-a',
            OAuthAuthorizationAttempt::hashState($state),
            'pkce-verifier-secret',
            'tenant-a',
            'subject-a',
            'session-a',
            'https://app.example.test/oauth/callback',
            ['crm.read'],
            100,
            $expiresAt,
        );
    }
}
