<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/**
 * Immutable, provider-neutral confirmation record. It deliberately contains no
 * CRM request body, endpoint, SQL, or raw confirmation/idempotency secret.
 */
final class CrmWriteOperation
{
    public const STATE_PENDING = 'pending';
    public const STATE_CONSUMED = 'consumed';
    public const STATE_EXPIRED = 'expired';
    public const STATE_REJECTED = 'rejected';

    /**
     * @param self::STATE_* $state
     */
    public function __construct(
        private readonly string $operationId,
        private readonly string $tenantId,
        private readonly string $actorId,
        private readonly string $operation,
        private readonly string $normalizedPayloadHash,
        private readonly string $summary,
        private readonly string $confirmationTokenHash,
        private readonly int $createdAt,
        private readonly int $expiresAt,
        private readonly string $state = self::STATE_PENDING,
        private readonly ?int $consumedAt = null,
        private readonly ?string $idempotencyKeyHash = null,
        private readonly ?string $requestFingerprint = null,
    ) {
        $this->validate();
    }

    public function operationId(): string
    {
        return $this->operationId;
    }
    public function tenantId(): string
    {
        return $this->tenantId;
    }
    public function actorId(): string
    {
        return $this->actorId;
    }
    public function operation(): string
    {
        return $this->operation;
    }
    public function normalizedPayloadHash(): string
    {
        return $this->normalizedPayloadHash;
    }
    public function summary(): string
    {
        return $this->summary;
    }
    public function confirmationTokenHash(): string
    {
        return $this->confirmationTokenHash;
    }
    public function createdAt(): int
    {
        return $this->createdAt;
    }
    public function expiresAt(): int
    {
        return $this->expiresAt;
    }
    public function state(): string
    {
        return $this->state;
    }
    public function consumedAt(): ?int
    {
        return $this->consumedAt;
    }
    public function idempotencyKeyHash(): ?string
    {
        return $this->idempotencyKeyHash;
    }
    public function requestFingerprint(): ?string
    {
        return $this->requestFingerprint;
    }

    /** Hash raw confirmation tokens before constructing this DTO. */
    public static function hashConfirmationToken(string $token): string
    {
        if ($token === '') {
            throw new \InvalidArgumentException('Confirmation token must not be empty.');
        }

        return hash('sha256', $token);
    }

    /**
     * Safe, structured audit data. Human summary and every secret are excluded.
     *
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return [
            'operation_id' => $this->operationId,
            'tenant_id' => $this->tenantId,
            'actor_id' => $this->actorId,
            'operation' => $this->operation,
            'normalized_payload_hash' => $this->normalizedPayloadHash,
            'state' => $this->state,
            'expires_at' => $this->expiresAt,
            'idempotency_key_fingerprint' => $this->idempotencyKeyHash === null
                ? null
                : substr($this->idempotencyKeyHash, 0, 12),
        ];
    }

    private function validate(): void
    {
        foreach ([$this->operationId, $this->tenantId, $this->actorId, $this->operation] as $value) {
            if ($value === '' || strlen($value) > 255) {
                throw new \InvalidArgumentException(
                    'CRM write operation bindings must be non-empty strings up to 255 bytes.',
                );
            }
        }
        if (
            !preg_match('/^[a-f0-9]{64}$/D', $this->normalizedPayloadHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $this->confirmationTokenHash)
        ) {
            throw new \InvalidArgumentException('CRM write operation hashes must be SHA-256 hex digests.');
        }
        if (strlen($this->summary) > 8192 || $this->createdAt <= 0 || $this->expiresAt <= $this->createdAt) {
            throw new \InvalidArgumentException('CRM write operation summary or TTL is invalid.');
        }
        if (
            !in_array(
                $this->state,
                [self::STATE_PENDING, self::STATE_CONSUMED, self::STATE_EXPIRED, self::STATE_REJECTED],
                true,
            )
        ) {
            throw new \InvalidArgumentException('CRM write operation state is invalid.');
        }
        if ($this->state === self::STATE_CONSUMED && $this->consumedAt === null) {
            throw new \InvalidArgumentException('Consumed CRM write operations require a consumption time.');
        }
    }
}
