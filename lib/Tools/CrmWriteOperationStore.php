<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/**
 * Persistence boundary for confirmation records only. Implementations must not
 * invoke a CRM provider or accept provider methods, URLs, SQL, or payloads.
 */
interface CrmWriteOperationStore
{
    public function create(CrmWriteOperation $operation): CrmWriteOperation;

    /**
     * Atomically consumes one pending confirmation or returns an idempotency replay.
     * requestFingerprint must be a SHA-256 digest of the server-normalized request.
     */
    public function consume(
        string $operationId,
        string $confirmationToken,
        string $tenantId,
        string $actorId,
        string $idempotencyKey,
        string $requestFingerprint,
        ?int $consumedAt = null,
    ): CrmWriteOperationConsumeResult;

    /** Marks a still-pending confirmation as rejected without exposing its payload. */
    public function reject(string $operationId, string $tenantId, string $actorId, ?int $rejectedAt = null): bool;
}
