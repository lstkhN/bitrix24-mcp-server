<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Plaintext tokens for a narrowly scoped provider request or refresh operation.
 * This type is intentionally separate from OAuthConnection metadata.
 */
final class OAuthConnectionTokens
{
    public function __construct(
        private readonly string $accessToken,
        private readonly ?string $refreshToken,
    ) {
        if ($this->accessToken === '') {
            throw new \InvalidArgumentException('OAuth access token cannot be empty.');
        }
        if ($this->refreshToken === '') {
            throw new \InvalidArgumentException('OAuth refresh token must be null or non-empty.');
        }
    }

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function refreshToken(): ?string
    {
        return $this->refreshToken;
    }
}
