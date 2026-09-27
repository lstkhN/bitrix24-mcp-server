<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * An opaque, signed OAuth state value. It contains no provider or user data.
 */
final class OAuthState
{
    public function __construct(
        private readonly string $value,
        private readonly int $expiresAt,
    ) {
    }

    public function value(): string
    {
        return $this->value;
    }

    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
}
