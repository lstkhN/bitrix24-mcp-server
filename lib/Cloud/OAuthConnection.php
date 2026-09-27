<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Immutable, non-secret OAuth connection metadata.
 */
final class OAuthConnection
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REAUTHORIZATION_REQUIRED = 'reauthorization_required';
    public const STATUS_REVOKED = 'revoked';

    /** @var list<string> */
    private readonly array $scopes;

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly string $subjectId,
        private readonly string $connectionId,
        array $scopes,
        private readonly ?int $expiresAt,
        private readonly string $status = self::STATUS_ACTIVE,
        private readonly int $version = 0,
        private readonly ?int $createdAt = null,
        private readonly ?int $updatedAt = null,
        private readonly ?int $revokedAt = null,
    ) {
        foreach ([$this->tenantId, $this->subjectId, $this->connectionId] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 255) {
                throw new \InvalidArgumentException(
                    'OAuth connection identifiers must be non-empty strings up to 255 bytes.',
                );
            }
        }

        if (
            !in_array(
                $this->status,
                [self::STATUS_ACTIVE, self::STATUS_REAUTHORIZATION_REQUIRED, self::STATUS_REVOKED],
                true,
            )
        ) {
            throw new \InvalidArgumentException('OAuth connection status is invalid.');
        }
        if ($this->version < 0) {
            throw new \InvalidArgumentException('OAuth connection version cannot be negative.');
        }

        $normalizedScopes = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || $scope === '' || strlen($scope) > 255) {
                throw new \InvalidArgumentException('OAuth scopes must be non-empty strings up to 255 bytes.');
            }
            $normalizedScopes[$scope] = true;
        }
        $this->scopes = array_keys($normalizedScopes);
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function subjectId(): string
    {
        return $this->subjectId;
    }

    public function connectionId(): string
    {
        return $this->connectionId;
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return $this->scopes;
    }

    public function expiresAt(): ?int
    {
        return $this->expiresAt;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function createdAt(): ?int
    {
        return $this->createdAt;
    }

    public function updatedAt(): ?int
    {
        return $this->updatedAt;
    }

    public function revokedAt(): ?int
    {
        return $this->revokedAt;
    }
}
