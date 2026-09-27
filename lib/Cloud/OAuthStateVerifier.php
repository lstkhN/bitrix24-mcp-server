<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Immutable issuer and one-time verifier for signed OAuth state values.
 *
 * The caller owns the returned verifier from OAuthStateVerification::nextVerifier()
 * and can persist it by any appropriate means. This class performs no I/O or storage.
 */
final class OAuthStateVerifier
{
    private const VERSION = 'v1';
    private const NONCE_LENGTH = 43;
    private const SIGNATURE_LENGTH = 43;

    /** @var list<string> SHA-256 fingerprints of already consumed states */
    private readonly array $consumed;

    /**
     * @param list<string> $consumed
     */
    private function __construct(
        private readonly string $signingKey,
        array $consumed = [],
    ) {
        $this->consumed = $consumed;
    }

    public static function withSigningKey(string $signingKey): self
    {
        if (strlen($signingKey) < 32) {
            throw new \InvalidArgumentException('OAuth state signing key must be at least 32 bytes.');
        }

        return new self($signingKey);
    }

    /**
     * Issues a state valid for the supplied positive lifetime in seconds.
     */
    public function issue(int $lifetimeSeconds, ?int $now = null): OAuthState
    {
        $now ??= time();
        if ($lifetimeSeconds < 1 || $now < 0) {
            throw new \InvalidArgumentException('OAuth state lifetime and current time must be positive.');
        }

        $expiresAt = $now + $lifetimeSeconds;
        if ($expiresAt < $now) {
            throw new \InvalidArgumentException('OAuth state expiry overflow.');
        }

        $nonce = self::base64UrlEncode(random_bytes(32));
        $payload = self::VERSION . '.' . $nonce . '.' . $expiresAt;
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey, true));

        return new OAuthState($payload . '.' . $signature, $expiresAt);
    }

    /**
     * Verifies and consumes a state. Use nextVerifier() after success to reject replays.
     */
    public function consume(string $state, ?int $now = null): OAuthStateVerification
    {
        $now ??= time();
        if ($now < 0) {
            throw new \InvalidArgumentException('Current time must be positive.');
        }

        $parts = explode('.', $state);
        if (
            count($parts) !== 4
            || $parts[0] !== self::VERSION
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $parts[1]) !== 1
            || !self::isCanonicalTimestamp($parts[2])
            || preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $parts[3]) !== 1
        ) {
            return new OAuthStateVerification(false, null);
        }

        $payload = implode('.', array_slice($parts, 0, 3));
        $expected = self::base64UrlEncode(hash_hmac('sha256', $payload, $this->signingKey, true));
        if (!hash_equals($expected, $parts[3])) {
            return new OAuthStateVerification(false, null);
        }

        $expiresAt = (int) $parts[2];
        if ($expiresAt <= $now) {
            return new OAuthStateVerification(false, null);
        }

        $fingerprint = hash('sha256', $state);
        if (in_array($fingerprint, $this->consumed, true)) {
            return new OAuthStateVerification(false, null);
        }

        return new OAuthStateVerification(true, new self($this->signingKey, [...$this->consumed, $fingerprint]));
    }

    private static function isCanonicalTimestamp(string $value): bool
    {
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $value) !== 1) {
            return false;
        }

        return (string) (int) $value === $value;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
