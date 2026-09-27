<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Stateless PKCE S256 primitives. Callers must retain the verifier privately.
 */
final class Pkce
{
    private const MIN_VERIFIER_LENGTH = 43;
    private const MAX_VERIFIER_LENGTH = 128;

    private function __construct()
    {
    }

    /**
     * Generates an RFC 7636 verifier using 32--96 random bytes.
     */
    public static function generateVerifier(int $randomBytes = 64): string
    {
        if ($randomBytes < 32 || $randomBytes > 96) {
            throw new \InvalidArgumentException('PKCE random bytes must be between 32 and 96.');
        }

        return self::base64UrlEncode(random_bytes($randomBytes));
    }

    /**
     * Returns the RFC 7636 S256 challenge for a valid verifier.
     */
    public static function challengeS256(string $verifier): string
    {
        if (!self::isValidVerifier($verifier)) {
            throw new \InvalidArgumentException('Invalid PKCE verifier.');
        }

        return self::base64UrlEncode(hash('sha256', $verifier, true));
    }

    private static function isValidVerifier(string $verifier): bool
    {
        $length = strlen($verifier);

        return $length >= self::MIN_VERIFIER_LENGTH
            && $length <= self::MAX_VERIFIER_LENGTH
            && preg_match('/\A[A-Za-z0-9\-._~]+\z/D', $verifier) === 1;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
