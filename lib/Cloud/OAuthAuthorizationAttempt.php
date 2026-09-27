<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Immutable authorization request data. The PKCE verifier is plaintext only
 * while this value is in process memory; stores must encrypt it at rest.
 */
final class OAuthAuthorizationAttempt
{
    /** @var list<string> */
    private readonly array $scopes;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly string $attemptId,
        private readonly string $stateHash,
        private readonly string $pkceVerifier,
        private readonly string $tenantId,
        private readonly string $subjectId,
        private readonly string $sessionBinding,
        private readonly string $redirectUri,
        array $scopes,
        private readonly int $createdAt,
        private readonly int $expiresAt,
        private readonly ?int $consumedAt = null,
    ) {
        foreach ([$this->attemptId, $this->tenantId, $this->subjectId, $this->sessionBinding] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 255) {
                throw new \InvalidArgumentException(
                    'OAuth authorization attempt bindings must be non-empty strings up to 255 bytes.',
                );
            }
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $this->stateHash) !== 1) {
            throw new \InvalidArgumentException('OAuth authorization attempt state hash must be a SHA-256 hex digest.');
        }
        if ($this->pkceVerifier === '' || strlen($this->pkceVerifier) > 128) {
            throw new \InvalidArgumentException('OAuth authorization attempt PKCE verifier is invalid.');
        }
        if ($this->redirectUri === '' || strlen($this->redirectUri) > 2048) {
            throw new \InvalidArgumentException('OAuth authorization attempt redirect URI is invalid.');
        }
        if ($this->createdAt <= 0 || $this->expiresAt <= $this->createdAt) {
            throw new \InvalidArgumentException('OAuth authorization attempt timestamps are invalid.');
        }
        if ($this->consumedAt !== null && $this->consumedAt < $this->createdAt) {
            throw new \InvalidArgumentException('OAuth authorization attempt consumption timestamp is invalid.');
        }

        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || strlen($scope) > 255) {
                throw new \InvalidArgumentException(
                    'OAuth authorization attempt scopes must be non-empty strings up to 255 bytes.',
                );
            }
            $normalizedScopes[$scope] = true;
        }
        $this->scopes = array_keys($normalizedScopes);
    }

    public static function hashState(string $state): string
    {
        if ($state === '') {
            throw new \InvalidArgumentException('OAuth state cannot be empty.');
        }

        return hash('sha256', $state);
    }

    public function attemptId(): string
    {
        return $this->attemptId;
    }
    public function stateHash(): string
    {
        return $this->stateHash;
    }
    public function pkceVerifier(): string
    {
        return $this->pkceVerifier;
    }
    public function tenantId(): string
    {
        return $this->tenantId;
    }
    public function subjectId(): string
    {
        return $this->subjectId;
    }
    public function sessionBinding(): string
    {
        return $this->sessionBinding;
    }
    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
    public function consumedAt(): ?int
    {
        return $this->consumedAt;
    }
}
