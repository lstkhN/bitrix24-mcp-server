<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Versioned token encryption using XChaCha20-Poly1305-IETF.
 *
 * The serialized value is: v1.<key-id>.<nonce>.<ciphertext>, where binary
 * values are encoded as unpadded base64url.
 */
final class SodiumTokenEncryptor implements TokenEncryptor
{
    private const VERSION = 'v1';
    private const KEY_ID_PATTERN = '/\A[A-Za-z0-9_-]{1,64}\z/D';
    private const BASE64_URL_PATTERN = '/\A[A-Za-z0-9_-]+\z/D';

    /** @var array<string, string> */
    private readonly array $keys;

    /**
     * @param array<string, string> $keys Key ID to a binary 32-byte key map.
     */
    public function __construct(
        private readonly string $activeKeyId,
        array $keys,
    ) {
        if (!extension_loaded('sodium')) {
            throw new \LogicException('The sodium extension is required for token encryption.');
        }

        if (preg_match(self::KEY_ID_PATTERN, $this->activeKeyId) !== 1) {
            throw new \InvalidArgumentException('Token key ID has an invalid format.');
        }

        foreach ($keys as $keyId => $key) {
            if (!is_string($keyId) || preg_match(self::KEY_ID_PATTERN, $keyId) !== 1 || !is_string($key)) {
                throw new \InvalidArgumentException('Token key ring is invalid.');
            }
            if (strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new \InvalidArgumentException('Token encryption keys must be exactly 32 bytes.');
            }
        }

        if (!array_key_exists($this->activeKeyId, $keys)) {
            throw new \InvalidArgumentException('The active token key is not in the key ring.');
        }

        $this->keys = $keys;
    }

    public function encrypt(string $plaintext, string $aad): string
    {
        $this->assertAad($aad);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $aad,
            $nonce,
            $this->keys[$this->activeKeyId],
        );

        return implode('.', [
            self::VERSION,
            $this->activeKeyId,
            self::base64UrlEncode($nonce),
            self::base64UrlEncode($ciphertext),
        ]);
    }

    public function decrypt(string $ciphertext, string $aad): string
    {
        $this->assertAad($aad);
        [$keyId, $nonce, $encrypted] = $this->parse($ciphertext);

        if (!array_key_exists($keyId, $this->keys)) {
            throw new UnknownTokenKeyException('Token cannot be decrypted.');
        }

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $encrypted,
                $aad,
                $nonce,
                $this->keys[$keyId],
            );
        } catch (\SodiumException $exception) {
            throw new TokenAuthenticationException('Token cannot be decrypted.', 0, $exception);
        }

        if ($plaintext === false) {
            throw new TokenAuthenticationException('Token cannot be decrypted.');
        }

        return $plaintext;
    }

    private function assertAad(string $aad): void
    {
        if ($aad === '') {
            throw new TokenEncryptionException('Token encryption requires non-empty additional authenticated data.');
        }
    }

    /**
     * @return array{string, string, string}
     */
    private function parse(string $serialized): array
    {
        $parts = explode('.', $serialized);
        if (
            count($parts) !== 4
            || $parts[0] !== self::VERSION
            || preg_match(self::KEY_ID_PATTERN, $parts[1]) !== 1
        ) {
            throw new MalformedTokenException('Token has an invalid format.');
        }

        $nonce = self::base64UrlDecode($parts[2]);
        $encrypted = self::base64UrlDecode($parts[3]);
        if (
            $nonce === null
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            || $encrypted === null
            || strlen($encrypted) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
        ) {
            throw new MalformedTokenException('Token has an invalid format.');
        }

        return [$parts[1], $nonce, $encrypted];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match(self::BASE64_URL_PATTERN, $value) !== 1) {
            return null;
        }

        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if ($decoded === false || self::base64UrlEncode($decoded) !== $value) {
            return null;
        }

        return $decoded;
    }
}
