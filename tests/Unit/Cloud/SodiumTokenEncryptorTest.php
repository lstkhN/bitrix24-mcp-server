<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Cloud;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\MalformedTokenException;
use Bitrix24\Mcp\Cloud\SodiumTokenEncryptor;
use Bitrix24\Mcp\Cloud\TokenAuthenticationException;
use Bitrix24\Mcp\Cloud\UnknownTokenKeyException;

final class SodiumTokenEncryptorTest extends TestCase
{
    private const AAD = 'bitrix24:portal-42:oauth-token';

    public function testRoundTrip(): void
    {
        $encryptor = $this->encryptor();

        $ciphertext = $encryptor->encrypt('access-token-secret', self::AAD);

        self::assertMatchesRegularExpression('/\Av1\.current\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $ciphertext);
        self::assertSame('access-token-secret', $encryptor->decrypt($ciphertext, self::AAD));
    }

    public function testEachEncryptionUsesDifferentCiphertext(): void
    {
        $encryptor = $this->encryptor();

        self::assertNotSame(
            $encryptor->encrypt('access-token-secret', self::AAD),
            $encryptor->encrypt('access-token-secret', self::AAD),
        );
    }

    public function testRejectsWrongAad(): void
    {
        $encryptor = $this->encryptor();
        $ciphertext = $encryptor->encrypt('access-token-secret', self::AAD);

        $this->expectException(TokenAuthenticationException::class);
        $encryptor->decrypt($ciphertext, 'bitrix24:another-portal:oauth-token');
    }

    public function testRejectsTamperedCiphertext(): void
    {
        $encryptor = $this->encryptor();
        $ciphertext = $encryptor->encrypt('access-token-secret', self::AAD);
        $parts = explode('.', $ciphertext);
        $ciphertextSegment = $parts[3];
        $lastChar = $ciphertextSegment[-1];
        $newLastChar = $lastChar === 'A' ? 'B' : 'A';
        $parts[3] = substr($ciphertextSegment, 0, -1) . $newLastChar;
        $tampered = implode('.', $parts);

        $this->expectException(TokenAuthenticationException::class);
        $encryptor->decrypt($tampered, self::AAD);
    }

    #[DataProvider('malformedTokens')]
    public function testRejectsMalformedTokens(string $ciphertext): void
    {
        $this->expectException(MalformedTokenException::class);
        $this->encryptor()->decrypt($ciphertext, self::AAD);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTokens(): iterable
    {
        yield 'empty' => [''];
        yield 'wrong version' => ['v2.current.nonce.ciphertext'];
        yield 'missing part' => ['v1.current.nonce'];
        yield 'invalid base64url' => ['v1.current.!!!!.!!!!'];
        yield 'short nonce' => ['v1.current.YQ.YQ'];
    }

    public function testKeyRotationCanReadOldKey(): void
    {
        $old = str_repeat('o', 32);
        $ciphertext = (new SodiumTokenEncryptor('old', ['old' => $old]))->encrypt('access-token-secret', self::AAD);
        $rotated = new SodiumTokenEncryptor('new', ['old' => $old, 'new' => str_repeat('n', 32)]);

        self::assertSame('access-token-secret', $rotated->decrypt($ciphertext, self::AAD));

        $unknownKey = str_replace('v1.old.', 'v1.retired.', $ciphertext);
        $this->expectException(UnknownTokenKeyException::class);
        $rotated->decrypt($unknownKey, self::AAD);
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SodiumTokenEncryptor('current', ['current' => 'too short']);
    }

    private function encryptor(): SodiumTokenEncryptor
    {
        return new SodiumTokenEncryptor('current', ['current' => str_repeat('k', 32)]);
    }
}
