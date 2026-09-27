<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Session;

/**
 * Memory-backed session store for tests / stateless environments.
 * Not suitable for production concurrent box/cloud deployments.
 */
final class MemorySessionStore implements SessionStore
{
    /** @var array<string, array{tokenHash: string, initialized: bool, createdAt: int, revoked: bool, revokedAt: ?int, principal: ?string}> */
    private array $store = [];

    private int $ttlSeconds;

    public function __construct(int $ttlSeconds = 3600)
    {
        $this->ttlSeconds = $ttlSeconds;
    }

    public function create(string $tokenHash, ?string $principal = null): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $this->store[$sessionId] = [
            'tokenHash' => $tokenHash,
            'initialized' => false,
            'createdAt' => time(),
            'revoked' => false,
            'revokedAt' => null,
            'principal' => $principal,
        ];
        return $sessionId;
    }

    public function get(string $sessionId, string $tokenHash): ?array
    {
        if (!isset($this->store[$sessionId])) {
            return null;
        }
        $session = $this->store[$sessionId];

        if ($session['revoked']) {
            return null;
        }

        if ($session['tokenHash'] !== $tokenHash) {
            return null;
        }

        if ((time() - $session['createdAt']) > $this->ttlSeconds) {
            $this->revoke($sessionId, $tokenHash);
            return null;
        }

        return $session;
    }

    public function setInitialized(string $sessionId, string $tokenHash): bool
    {
        $session = $this->get($sessionId, $tokenHash);
        if ($session === null) {
            return false;
        }
        $this->store[$sessionId]['initialized'] = true;
        return true;
    }

    public function revoke(string $sessionId, string $tokenHash): bool
    {
        $session = $this->get($sessionId, $tokenHash);
        if ($session === null) {
            return false;
        }
        $this->store[$sessionId]['revoked'] = true;
        $this->store[$sessionId]['revokedAt'] = time();
        return true;
    }

    public function purge(): void
    {
        $now = time();
        foreach ($this->store as $id => $session) {
            if ($session['revoked'] || (($now - $session['createdAt']) > $this->ttlSeconds)) {
                unset($this->store[$id]);
            }
        }
    }
}
