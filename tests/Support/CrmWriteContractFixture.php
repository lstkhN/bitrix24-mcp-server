<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Support;

/**
 * Test-only in-memory contract fixture. It models the proposed CRM write
 * workflow without calling Bitrix or providing production write behaviour.
 */
final class CrmWriteContractFixture
{
    /** @var array<string, array<string, mixed>> */
    private array $operations = [];

    /** @var array<string, array<string, mixed>> */
    private array $idempotency = [];

    /** @var list<array<string, mixed>> */
    private array $audit = [];

    private int $sequence = 0;

    private int $mutations = 0;

    public function __construct(private int $now = 1735732800)
    {
    }

    /**
     * @param array{entity_type: string, entity_id: int} $target
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function preview(
        string $actor,
        string $tenant,
        string $operation,
        array $target,
        array $input,
        bool $dryRun = true
    ): array {
        if (!$dryRun) {
            return $this->failure('validation_error');
        }

        ksort($input);
        $operationId = 'op_' . (++$this->sequence);
        $token = 'confirmation-secret-' . $this->sequence;
        $expiresAt = $this->now + 300;
        $planHash = hash('sha256', json_encode([$tenant, $operation, $target, $input], JSON_THROW_ON_ERROR));

        $this->operations[$operationId] = [
            'actor' => $actor,
            'tenant' => $tenant,
            'operation' => $operation,
            'target' => $target,
            'plan_hash' => $planHash,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
            'consumed' => false,
        ];

        $this->audit('preview_created', $actor, $tenant, $operationId, $operation, $target, $planHash, $input);
        $this->audit('confirmation_issued', $actor, $tenant, $operationId, $operation, $target, $planHash, $input);

        return [
            'status' => 'awaiting_confirmation',
            'operation_id' => $operationId,
            'confirmation_token' => $token,
            'dry_run' => true,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return array<string, mixed> */
    public function execute(
        string $actor,
        string $tenant,
        string $operationId,
        string $confirmationToken,
        string $idempotencyKey,
        bool $dryRun = false
    ): array {
        if ($dryRun || !isset($this->operations[$operationId])) {
            return $this->failure('validation_error');
        }

        $plan = $this->operations[$operationId];
        $scope = implode('|', [$actor, $tenant, $plan['operation'], $idempotencyKey]);
        $requestHash = hash('sha256', implode('|', [$plan['plan_hash'], hash('sha256', $confirmationToken), 'policy-v1']));

        if (isset($this->idempotency[$scope])) {
            $stored = $this->idempotency[$scope];
            if ($stored['request_hash'] !== $requestHash) {
                return $this->failure('idempotency_conflict');
            }

            $this->audit('idempotency_replayed', $actor, $tenant, $operationId, $plan['operation'], $plan['target'], $plan['plan_hash'], []);

            return array_replace($stored['result'], ['status' => 'replayed', 'replayed' => true]);
        }

        if ($plan['actor'] !== $actor || $plan['tenant'] !== $tenant) {
            return $this->failure('confirmation_mismatch');
        }
        if ($plan['expires_at'] <= $this->now) {
            $this->audit('confirmation_expired', $actor, $tenant, $operationId, $plan['operation'], $plan['target'], $plan['plan_hash'], []);

            return $this->failure('confirmation_expired');
        }
        if ($plan['consumed']) {
            return $this->failure('confirmation_already_used');
        }
        if (!hash_equals($plan['token_hash'], hash('sha256', $confirmationToken))) {
            return $this->failure('confirmation_mismatch');
        }

        $this->operations[$operationId]['consumed'] = true;
        ++$this->mutations;
        $result = [
            'status' => 'succeeded',
            'operation_id' => $operationId,
            'dry_run' => false,
            'idempotency_key_fingerprint' => substr(hash('sha256', $idempotencyKey), 0, 12),
            'target_ids' => [$plan['target']['entity_id']],
        ];
        $this->idempotency[$scope] = ['request_hash' => $requestHash, 'result' => $result];
        $this->audit('execute_succeeded', $actor, $tenant, $operationId, $plan['operation'], $plan['target'], $plan['plan_hash'], []);

        return $result;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }

    public function mutationCount(): int
    {
        return $this->mutations;
    }

    /** @return list<array<string, mixed>> */
    public function auditEvents(): array
    {
        return $this->audit;
    }

    /** @return array{status: string, code: string} */
    private function failure(string $code): array
    {
        return ['status' => 'failed', 'code' => $code];
    }

    /**
     * @param array{entity_type: string, entity_id: int} $target
     * @param array<string, mixed> $input
     */
    private function audit(
        string $event,
        string $actor,
        string $tenant,
        string $operationId,
        string $operation,
        array $target,
        string $planHash,
        array $input
    ): void {
        $this->audit[] = [
            'event' => $event,
            'actor' => $actor,
            'tenant' => $tenant,
            'operation_id' => $operationId,
            'operation' => $operation,
            'target' => $target,
            'normalized_input_hash' => $planHash,
            'changed_fields' => array_values(array_intersect(array_keys($input), ['STAGE_ID'])),
        ];
    }
}
