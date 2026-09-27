<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/** PDO confirmation store; it has no CRM client, transport, or execution capability. */
final class PdoCrmWriteOperationStore implements CrmWriteOperationStore
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function create(CrmWriteOperation $operation): CrmWriteOperation
    {
        if ($operation->state() !== CrmWriteOperation::STATE_PENDING) {
            throw new \InvalidArgumentException('Only pending CRM write operations can be created.');
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO crm_write_operations ('
                . 'operation_id, tenant_id, actor_id, operation, normalized_payload_hash, summary, '
                . 'confirmation_token_hash, created_at, expires_at, state, consumed_at, '
                . 'idempotency_key_hash, request_fingerprint'
                . ') VALUES ('
                . ':operation_id, :tenant_id, :actor_id, :operation, :normalized_payload_hash, :summary, '
                . ':confirmation_token_hash, :created_at, :expires_at, :state, NULL, NULL, NULL'
                . ')'
            );
            $statement->execute([
                ':operation_id' => $operation->operationId(),
                ':tenant_id' => $operation->tenantId(),
                ':actor_id' => $operation->actorId(),
                ':operation' => $operation->operation(),
                ':normalized_payload_hash' => $operation->normalizedPayloadHash(),
                ':summary' => $operation->summary(),
                ':confirmation_token_hash' => $operation->confirmationTokenHash(),
                ':created_at' => $operation->createdAt(),
                ':expires_at' => $operation->expiresAt(),
                ':state' => CrmWriteOperation::STATE_PENDING,
            ]);
        } catch (\PDOException $exception) {
            throw new \RuntimeException('CRM write operation could not be stored.', 0, $exception);
        }

        return $operation;
    }

    public function consume(
        string $operationId,
        string $confirmationToken,
        string $tenantId,
        string $actorId,
        string $idempotencyKey,
        string $requestFingerprint,
        ?int $consumedAt = null,
    ): CrmWriteOperationConsumeResult {
        $this->assertConsumeInput(
            $operationId,
            $confirmationToken,
            $tenantId,
            $actorId,
            $idempotencyKey,
            $requestFingerprint,
        );
        $consumedAt ??= time();

        try {
            $this->pdo->beginTransaction();
            $operation = $this->findBoundOperation($operationId, $tenantId, $actorId);
            if ($operation === null) {
                return $this->commitResult(CrmWriteOperationConsumeResult::REJECTED, null);
            }
            if ($operation->state() === CrmWriteOperation::STATE_PENDING && $operation->expiresAt() <= $consumedAt) {
                $this->transition(
                    $operationId,
                    CrmWriteOperation::STATE_PENDING,
                    CrmWriteOperation::STATE_EXPIRED,
                    null,
                );
                return $this->commitResult(CrmWriteOperationConsumeResult::EXPIRED, $this->findById($operationId));
            }
            if ($operation->state() === CrmWriteOperation::STATE_EXPIRED) {
                return $this->commitResult(CrmWriteOperationConsumeResult::EXPIRED, $operation);
            }
            $keyHash = $this->idempotencyKeyHash($tenantId, $actorId, $operation->operation(), $idempotencyKey);
            $existing = $this->findIdempotency($tenantId, $actorId, $operation->operation(), $keyHash);
            if ($existing !== null) {
                return $this->idempotencyResult($existing, $requestFingerprint);
            }
            if (
                $operation->state() !== CrmWriteOperation::STATE_PENDING
                || !hash_equals(
                    $operation->confirmationTokenHash(),
                    CrmWriteOperation::hashConfirmationToken($confirmationToken),
                )
            ) {
                return $this->commitResult(CrmWriteOperationConsumeResult::REJECTED, $operation);
            }

            try {
                $statement = $this->pdo->prepare(
                    'INSERT INTO crm_write_operation_idempotency ('
                    . 'tenant_id, actor_id, operation, idempotency_key_hash, request_fingerprint, '
                    . 'operation_id, created_at'
                    . ') VALUES (:tenant_id, :actor_id, :operation, :idempotency_key_hash, '
                    . ':request_fingerprint, :operation_id, :created_at)'
                );
                $statement->execute([
                    ':tenant_id' => $tenantId,
                    ':actor_id' => $actorId,
                    ':operation' => $operation->operation(),
                    ':idempotency_key_hash' => $keyHash,
                    ':request_fingerprint' => $requestFingerprint,
                    ':operation_id' => $operationId,
                    ':created_at' => $consumedAt,
                ]);
            } catch (\PDOException $exception) {
                $existing = $this->findIdempotency($tenantId, $actorId, $operation->operation(), $keyHash);
                if ($existing !== null) {
                    return $this->idempotencyResult($existing, $requestFingerprint);
                }
                throw $exception;
            }

            $statement = $this->pdo->prepare(
                'UPDATE crm_write_operations SET state = :consumed, consumed_at = :consumed_at, '
                . 'idempotency_key_hash = :idempotency_key_hash, request_fingerprint = :request_fingerprint '
                . 'WHERE operation_id = :operation_id AND tenant_id = :tenant_id AND actor_id = :actor_id '
                . 'AND state = :pending AND expires_at > :consumed_at '
                . 'AND confirmation_token_hash = :confirmation_token_hash'
            );
            $statement->execute([
                ':consumed' => CrmWriteOperation::STATE_CONSUMED,
                ':consumed_at' => $consumedAt,
                ':idempotency_key_hash' => $keyHash,
                ':request_fingerprint' => $requestFingerprint,
                ':operation_id' => $operationId,
                ':tenant_id' => $tenantId,
                ':actor_id' => $actorId,
                ':pending' => CrmWriteOperation::STATE_PENDING,
                ':confirmation_token_hash' => $operation->confirmationTokenHash(),
            ]);
            if ($statement->rowCount() !== 1) {
                $latest = $this->findBoundOperation($operationId, $tenantId, $actorId);
                if ($latest !== null && $latest->state() === CrmWriteOperation::STATE_EXPIRED) {
                    return $this->commitResult(CrmWriteOperationConsumeResult::EXPIRED, $latest);
                }
                return $this->commitResult(CrmWriteOperationConsumeResult::REJECTED, $latest);
            }

            return $this->commitResult(CrmWriteOperationConsumeResult::CONSUMED, $this->findById($operationId));
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof \RuntimeException) {
                throw $exception;
            }
            throw new \RuntimeException('CRM write confirmation could not be consumed.', 0, $exception);
        }
    }

    public function reject(string $operationId, string $tenantId, string $actorId, ?int $rejectedAt = null): bool
    {
        $this->assertBindings($operationId, $tenantId, $actorId);
        try {
            $statement = $this->pdo->prepare(
                'UPDATE crm_write_operations SET state = :rejected '
                . 'WHERE operation_id = :operation_id AND tenant_id = :tenant_id AND actor_id = :actor_id '
                . 'AND state = :pending AND expires_at > :now'
            );
            $statement->execute([
                ':rejected' => CrmWriteOperation::STATE_REJECTED,
                ':operation_id' => $operationId,
                ':tenant_id' => $tenantId,
                ':actor_id' => $actorId,
                ':pending' => CrmWriteOperation::STATE_PENDING,
                ':now' => $rejectedAt ?? time(),
            ]);
            return $statement->rowCount() === 1;
        } catch (\PDOException $exception) {
            throw new \RuntimeException('CRM write operation could not be rejected.', 0, $exception);
        }
    }

    private function idempotencyResult(array $row, string $requestFingerprint): CrmWriteOperationConsumeResult
    {
        if (!hash_equals((string) $row['request_fingerprint'], $requestFingerprint)) {
            return $this->commitResult(CrmWriteOperationConsumeResult::IDEMPOTENCY_CONFLICT, null);
        }
        return $this->commitResult(
            CrmWriteOperationConsumeResult::REPLAYED,
            $this->findById((string) $row['operation_id']),
        );
    }

    private function commitResult(string $outcome, ?CrmWriteOperation $operation): CrmWriteOperationConsumeResult
    {
        $this->pdo->commit();
        return new CrmWriteOperationConsumeResult($outcome, $operation);
    }

    private function transition(string $operationId, string $from, string $to, ?int $consumedAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE crm_write_operations SET state = :to_state, consumed_at = :consumed_at '
            . 'WHERE operation_id = :operation_id AND state = :from_state'
        );
        $statement->execute([
            ':to_state' => $to,
            ':consumed_at' => $consumedAt,
            ':operation_id' => $operationId,
            ':from_state' => $from,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function findIdempotency(string $tenantId, string $actorId, string $operation, string $keyHash): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT request_fingerprint, operation_id FROM crm_write_operation_idempotency '
            . 'WHERE tenant_id = :tenant_id AND actor_id = :actor_id AND operation = :operation '
            . 'AND idempotency_key_hash = :idempotency_key_hash'
        );
        $statement->execute([
            ':tenant_id' => $tenantId,
            ':actor_id' => $actorId,
            ':operation' => $operation,
            ':idempotency_key_hash' => $keyHash,
        ]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function findBoundOperation(string $operationId, string $tenantId, string $actorId): ?CrmWriteOperation
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM crm_write_operations WHERE operation_id = :operation_id '
            . 'AND tenant_id = :tenant_id AND actor_id = :actor_id'
        );
        $statement->execute([':operation_id' => $operationId, ':tenant_id' => $tenantId, ':actor_id' => $actorId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->operationFromRow($row);
    }

    private function findById(string $operationId): ?CrmWriteOperation
    {
        $statement = $this->pdo->prepare('SELECT * FROM crm_write_operations WHERE operation_id = :operation_id');
        $statement->execute([':operation_id' => $operationId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $this->operationFromRow($row);
    }

    /** @param array<string, mixed> $row */
    private function operationFromRow(array $row): CrmWriteOperation
    {
        return new CrmWriteOperation(
            (string) $row['operation_id'],
            (string) $row['tenant_id'],
            (string) $row['actor_id'],
            (string) $row['operation'],
            (string) $row['normalized_payload_hash'],
            (string) $row['summary'],
            (string) $row['confirmation_token_hash'],
            (int) $row['created_at'],
            (int) $row['expires_at'],
            (string) $row['state'],
            $row['consumed_at'] === null ? null : (int) $row['consumed_at'],
            $row['idempotency_key_hash'] === null ? null : (string) $row['idempotency_key_hash'],
            $row['request_fingerprint'] === null ? null : (string) $row['request_fingerprint'],
        );
    }

    private function idempotencyKeyHash(string $tenantId, string $actorId, string $operation, string $key): string
    {
        return hash('sha256', $tenantId . "\0" . $actorId . "\0" . $operation . "\0" . $key);
    }

    private function assertConsumeInput(
        string $operationId,
        string $token,
        string $tenantId,
        string $actorId,
        string $key,
        string $fingerprint,
    ): void {
        $this->assertBindings($operationId, $tenantId, $actorId);
        if ($token === '' || $key === '' || strlen($key) > 255 || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) {
            throw new \InvalidArgumentException('CRM write confirmation input is invalid.');
        }
    }

    private function assertBindings(string $operationId, string $tenantId, string $actorId): void
    {
        foreach ([$operationId, $tenantId, $actorId] as $binding) {
            if ($binding === '' || strlen($binding) > 255) {
                throw new \InvalidArgumentException(
                    'CRM write operation bindings must be non-empty strings up to 255 bytes.',
                );
            }
        }
    }
}
