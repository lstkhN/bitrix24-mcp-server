<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Provider-neutral persistence boundary for OAuth connections.
 *
 * Metadata methods never expose plaintext tokens. Callers must authorize the
 * tenant and subject before requesting tokens for a provider operation.
 */
interface OAuthConnectionStore
{
    public function createConnection(OAuthConnection $connection, OAuthConnectionTokens $tokens): OAuthConnection;

    public function getConnection(string $tenantId, string $subjectId, string $connectionId): ?OAuthConnection;

    public function getActiveTokens(string $tenantId, string $subjectId, string $connectionId): ?OAuthConnectionTokens;

    /**
     * Atomically replaces tokens only when the active row has expectedVersion.
     */
    public function compareAndSwapTokens(
        string $tenantId,
        string $subjectId,
        string $connectionId,
        int $expectedVersion,
        OAuthConnectionTokens $tokens,
        ?int $expiresAt,
    ): bool;

    /**
     * Locally blocks a connection even if a remote provider revoke later fails.
     */
    public function revokeConnection(string $tenantId, string $subjectId, string $connectionId, int $revokedAt): bool;

    public function deleteConnection(string $tenantId, string $subjectId, string $connectionId): bool;
}
