<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/** Result of reserving and consuming a confirmation; never represents a CRM write. */
final class CrmWriteOperationConsumeResult
{
    public const CONSUMED = 'consumed';
    public const REPLAYED = 'replayed';
    public const EXPIRED = 'expired';
    public const REJECTED = 'rejected';
    public const IDEMPOTENCY_CONFLICT = 'idempotency_conflict';

    public function __construct(
        private readonly string $outcome,
        private readonly ?CrmWriteOperation $operation,
    ) {
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function operation(): ?CrmWriteOperation
    {
        return $this->operation;
    }
}
