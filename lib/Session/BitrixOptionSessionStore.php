<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Session;

use Bitrix24\Mcp\Cloud\SodiumTokenEncryptor;
use Bitrix24\Mcp\Cloud\TokenEncryptor;
use Bitrix\Main\Config\Option;

/**
 * Bitrix Box safe session store using Option mechanism (not plaintext bearer).
 *
 * Requires a 32-byte secret option `session_encryption_key`. If absent or
 * invalid, the store fails closed (throws \RuntimeException) so that
 * session data is never encrypted with a weak/deterministic key.
 */
final class BitrixOptionSessionStore implements SessionStore
{
    private TokenEncryptor $encryptor;
    private string $moduleName = 'bitrix24.mcp';
    private int $ttlSeconds;

    public function __construct(?TokenEncryptor $encryptor = null, int $ttlSeconds = 3600)
    {
        $this->ttlSeconds = $ttlSeconds;
        $this->encryptor = $encryptor ?? $this->createDefaultEncryptor();
    }

    private function createDefaultEncryptor(): TokenEncryptor
    {
        $keyRaw = Option::get($this->moduleName, 'session_encryption_key', '');

        if ($keyRaw === '' || strlen($keyRaw) !== 32) {
            throw new \RuntimeException(
                'Session encryption key missing/invalid: '
                . 'option `session_encryption_key` must be exactly 32 bytes.'
            );
        }

        $keyId = 'box-key';
        return new SodiumTokenEncryptor($keyId, [$keyId => $keyRaw]);
    }

    public function create(string $tokenHash, ?string $principal = null): string
    {
        $sessionId = bin2hex(random_bytes(32));
        $payload = [
            'token_hash' => $tokenHash,
            'initialized' => false,
            'created_at' => time(),
            'revoked' => false,
            'revoked_at' => null,
            'principal' => $principal,
        ];
        $encrypted = $this->encryptor->encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $sessionId . ':' . $tokenHash
        );
        Option::set($this->moduleName, 'session_' . $sessionId, $encrypted);
        return $sessionId;
    }

    public function get(string $sessionId, string $tokenHash): ?array
    {
        $encrypted = Option::get($this->moduleName, 'session_' . $sessionId, '');
        if ($encrypted === '' || $encrypted === false) {
            return null;
        }
        try {
            $plaintext = $this->encryptor->decrypt($encrypted, $sessionId . ':' . $tokenHash);
            $payload = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Exception $e) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        if (($payload['revoked'] ?? false) === true) {
            return null;
        }

        if (($payload['token_hash'] ?? '') !== $tokenHash) {
            return null;
        }

        if (isset($payload['created_at']) && (time() - $payload['created_at']) > $this->ttlSeconds) {
            $this->revoke($sessionId, $tokenHash);
            return null;
        }

        return [
            'tokenHash' => $payload['token_hash'],
            'initialized' => $payload['initialized'] ?? false,
            'createdAt' => $payload['created_at'] ?? 0,
            'revoked' => $payload['revoked'] ?? false,
            'revokedAt' => $payload['revoked_at'] ?? null,
            'principal' => $payload['principal'] ?? null,
        ];
    }

    public function setInitialized(string $sessionId, string $tokenHash): bool
    {
        $session = $this->get($sessionId, $tokenHash);
        if ($session === null) {
            return false;
        }
        $payload = [
            'token_hash' => $tokenHash,
            'initialized' => true,
            'created_at' => $session['createdAt'],
            'revoked' => $session['revoked'],
            'revoked_at' => $session['revokedAt'],
            'principal' => $session['principal'],
        ];
        $encrypted = $this->encryptor->encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $sessionId . ':' . $tokenHash
        );
        Option::set($this->moduleName, 'session_' . $sessionId, $encrypted);
        return true;
    }

    public function revoke(string $sessionId, string $tokenHash): bool
    {
        $session = $this->get($sessionId, $tokenHash);
        if ($session === null) {
            return false;
        }
        $payload = [
            'token_hash' => $tokenHash,
            'initialized' => $session['initialized'],
            'created_at' => $session['createdAt'],
            'revoked' => true,
            'revoked_at' => time(),
            'principal' => $session['principal'],
        ];
        $encrypted = $this->encryptor->encrypt(
            json_encode($payload, JSON_THROW_ON_ERROR),
            $sessionId . ':' . $tokenHash
        );
        Option::set($this->moduleName, 'session_' . $sessionId, $encrypted);
        return true;
    }

    public function purge(): void
    {
        // Option-based purge is not implemented here; TTL handled by get().
        // For production, a scheduled task can clean `session_*` options older than TTL.
    }
}
