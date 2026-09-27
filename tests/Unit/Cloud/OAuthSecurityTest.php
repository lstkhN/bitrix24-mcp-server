<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Cloud;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Cloud\OAuthStateVerifier;
use Bitrix24\Mcp\Cloud\Pkce;

final class OAuthSecurityTest extends TestCase
{
    public function testPkceVerifierHasRequiredEntropyAndFormat(): void
    {
        $first = Pkce::generateVerifier();
        $second = Pkce::generateVerifier();

        self::assertSame(86, strlen($first));
        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{86}\z/', $first);
        self::assertNotSame($first, $second);
    }

    public function testPkceS256MatchesRfc7636Example(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', Pkce::challengeS256($verifier));
    }

    public function testPkceRejectsMalformedVerifierAndInvalidRandomSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Pkce::challengeS256('not valid');
    }

    public function testStateIsOpaqueRandomAndCanBeConsumedOnce(): void
    {
        $verifier = OAuthStateVerifier::withSigningKey(str_repeat('k', 32));
        $first = $verifier->issue(300, 1_000);
        $second = $verifier->issue(300, 1_000);

        self::assertNotSame($first->value(), $second->value());
        self::assertSame(1_300, $first->expiresAt());
        self::assertMatchesRegularExpression('/\Av1\.[A-Za-z0-9_-]{43}\.[0-9]+\.[A-Za-z0-9_-]{43}\z/', $first->value());

        $accepted = $verifier->consume($first->value(), 1_001);
        self::assertTrue($accepted->isAccepted());
        self::assertFalse($accepted->nextVerifier()->consume($first->value(), 1_001)->isAccepted());
    }

    public function testStateRejectsExpiredAndTamperedValues(): void
    {
        $verifier = OAuthStateVerifier::withSigningKey(str_repeat('k', 32));
        $state = $verifier->issue(10, 1_000);

        self::assertFalse($verifier->consume($state->value(), 1_010)->isAccepted());
        self::assertFalse($verifier->consume($state->value() . 'x', 1_001)->isAccepted());
    }

    /**
     * @dataProvider malformedStates
     */
    public function testStateRejectsMalformedInput(string $state): void
    {
        $verifier = OAuthStateVerifier::withSigningKey(str_repeat('k', 32));

        self::assertFalse($verifier->consume($state, 1_000)->isAccepted());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedStates(): iterable
    {
        yield 'empty' => [''];
        yield 'missing parts' => ['v1.only-two'];
        yield 'invalid nonce alphabet' => [
            'v1.!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!.1001.signature',
        ];
        yield 'zero timestamp' => [
            'v1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.0.'
            . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ];
        yield 'invalid signature alphabet' => [
            'v1.aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.1001.'
            . '!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!',
        ];
    }
}
