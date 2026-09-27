<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/**
 * Immutable provider-neutral confirmation audit record.
 *
 * Payload values are deliberately restricted to hashes and a small allowlist
 * of redacted operational metadata. It cannot carry CRM bodies or secrets.
 */
final class CrmAuditEvent
{
    public const PREVIEW_CREATED = 'preview_created';
    public const CONFIRMATION_CONSUMED = 'confirmation_consumed';
    public const REPLAYED = 'replayed';
    public const CONFLICT = 'conflict';
    public const EXPIRED = 'expired';
    public const REJECTED = 'rejected';
    public const EXECUTE_STARTED = 'execute_started';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    /**
     * @param array<string, int|string> $payload Safe hashes or allowlisted redacted metadata only.
     */
    public function __construct(
        private readonly string $eventId,
        private readonly int $occurredAt,
        private readonly string $tenantId,
        private readonly string $actorId,
        private readonly string $operationId,
        private readonly string $operation,
        private readonly string $eventType,
        private readonly array $payload = [],
    ) {
        $this->validate();
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): int
    {
        return $this->occurredAt;
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function operationId(): string
    {
        return $this->operationId;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /** @return array<string, int|string> */
    public function payload(): array
    {
        return $this->payload;
    }

    private function validate(): void
    {
        $bindings = [$this->eventId, $this->tenantId, $this->actorId, $this->operationId, $this->operation];
        foreach ($bindings as $binding) {
            if ($binding === '' || strlen($binding) > 255) {
                throw new \InvalidArgumentException(
                    'CRM audit event bindings must be non-empty strings up to 255 bytes.',
                );
            }
        }
        if ($this->occurredAt <= 0 || !in_array($this->eventType, self::eventTypes(), true)) {
            throw new \InvalidArgumentException('CRM audit event type or timestamp is invalid.');
        }
        foreach ($this->payload as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, self::payloadRules())) {
                throw new \InvalidArgumentException('CRM audit event payload contains a disallowed field.');
            }
            $rule = self::payloadRules()[$key];
            if (!is_int($value) && !is_string($value) || !$this->isSafePayloadValue($rule, $value)) {
                throw new \InvalidArgumentException('CRM audit event payload contains an unsafe value.');
            }
        }
    }

    /** @return list<string> */
    private static function eventTypes(): array
    {
        return [
            self::PREVIEW_CREATED,
            self::CONFIRMATION_CONSUMED,
            self::REPLAYED,
            self::CONFLICT,
            self::EXPIRED,
            self::REJECTED,
            self::EXECUTE_STARTED,
            self::SUCCEEDED,
            self::FAILED,
            self::UNKNOWN,
        ];
    }

    /** @return array<string, string> */
    private static function payloadRules(): array
    {
        return [
            'normalized_payload_hash' => 'sha256',
            'request_fingerprint' => 'sha256',
            'idempotency_key_fingerprint' => 'fingerprint',
            'policy_version' => 'identifier',
            'schema_version' => 'identifier',
            'risk_level' => 'risk',
            'correlation_id' => 'identifier',
            'upstream_request_id' => 'identifier',
            'error_code' => 'identifier',
            'attempt_count' => 'non_negative_int',
            'duration_ms' => 'non_negative_int',
            'target_count' => 'non_negative_int',
        ];
    }

    private function isSafePayloadValue(string $rule, int|string $value): bool
    {
        if ($rule === 'non_negative_int') {
            return is_int($value) && $value >= 0;
        }
        if (!is_string($value)) {
            return false;
        }
        return match ($rule) {
            'sha256' => (bool) preg_match('/^[a-f0-9]{64}$/D', $value),
            'fingerprint' => (bool) preg_match('/^[a-f0-9]{12}$/D', $value),
            'risk' => in_array($value, ['low', 'medium', 'high', 'critical'], true),
            'identifier' => (bool) preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $value),
            default => false,
        };
    }
}
