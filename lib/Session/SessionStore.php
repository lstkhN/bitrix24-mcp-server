<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Session;

/**
 * Provider-neutral secure session store for HTTP MCP lifecycle.
 *
 * - Cryptographically random opaque IDs (random_bytes + bin2hex)
 * - TTL expiration
 * - Token/principal binding (hash, never plaintext token storage)
 * - Initialized state tracking
 * - DELETE revocation
 */
interface SessionStore
{
    /**
     * Create a new session bound to the current auth token and principal.
     *
     * @param string $tokenHash Cryptographic hash of the auth token (e.g. sha256)
     * @param ?string $principal Optional principal identifier (user/session context)
     * @return string Opaque session ID
     */
    public function create(string $tokenHash, ?string $principal = null): string;

    /**
     * Retrieve session data if valid and not expired/revoked.
     *
     * @param string $sessionId
     * @param string $tokenHash Must match the bound token hash
     * @return array{tokenHash: string, initialized: bool, createdAt: int,
     *     revoked: bool, revokedAt: ?int, principal: ?string}|null
     */
    public function get(string $sessionId, string $tokenHash): ?array;

    /**
     * Mark session as initialized (after initialize request).
     */
    public function setInitialized(string $sessionId, string $tokenHash): bool;

    /**
     * Revoke a session (DELETE). After revocation get() must return null.
     */
    public function revoke(string $sessionId, string $tokenHash): bool;

    /**
     * Purge expired/revoked sessions (optional cleanup).
     */
    public function purge(): void;
}
