<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\CrmWriteOperation;
use Bitrix24\Mcp\Tools\CrmWriteOperationConsumeResult;
use Bitrix24\Mcp\Tools\PdoCrmWriteOperationStore;

final class PdoCrmWriteOperationStoreTest extends TestCase
{
    private \PDO $pdo;
    private PdoCrmWriteOperationStore $store;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(
                'PDO SQLite driver is unavailable; CRM confirmation store tests require sqlite::memory:.',
            );
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->exec((string) file_get_contents(dirname(__DIR__, 3) . '/docs/crm-write-operations-schema.sql'));
        $this->store = new PdoCrmWriteOperationStore($this->pdo);
    }

    public function testSameFingerprintReplaysAndDifferentFingerprintConflicts(): void
    {
        $this->store->create($this->operation('op-1', 'secret-one'));
        $fingerprint = hash('sha256', 'normalized-request-v1');

        self::assertSame(
            CrmWriteOperationConsumeResult::CONSUMED,
            $this->store->consume('op-1', 'secret-one', 'tenant-a', 'actor-a', 'key-a', $fingerprint, 101)->outcome(),
        );
        self::assertSame(
            CrmWriteOperationConsumeResult::REPLAYED,
            $this->store->consume('op-1', 'secret-one', 'tenant-a', 'actor-a', 'key-a', $fingerprint, 102)->outcome(),
        );
        self::assertSame(
            CrmWriteOperationConsumeResult::IDEMPOTENCY_CONFLICT,
            $this->store->consume(
                'op-1',
                'secret-one',
                'tenant-a',
                'actor-a',
                'key-a',
                hash('sha256', 'other'),
                103,
            )->outcome(),
        );
    }

    public function testExpiredAndMismatchedBindingsCannotConsume(): void
    {
        $this->store->create($this->operation('op-expired', 'secret-two', 100, 110));
        self::assertSame(
            CrmWriteOperationConsumeResult::EXPIRED,
            $this->store->consume(
                'op-expired',
                'secret-two',
                'tenant-a',
                'actor-a',
                'key-b',
                hash('sha256', 'request'),
                110,
            )->outcome(),
        );

        $this->store->create($this->operation('op-bound', 'secret-three'));
        self::assertSame(
            CrmWriteOperationConsumeResult::REJECTED,
            $this->store->consume(
                'op-bound',
                'secret-three',
                'tenant-b',
                'actor-a',
                'key-c',
                hash('sha256', 'request'),
                101,
            )->outcome(),
        );
        self::assertSame(
            CrmWriteOperationConsumeResult::REJECTED,
            $this->store->consume(
                'op-bound',
                'secret-three',
                'tenant-a',
                'actor-b',
                'key-c',
                hash('sha256', 'request'),
                101,
            )->outcome(),
        );
    }

    public function testOnlyOneConcurrentDeliveryCanConsume(): void
    {
        $this->store->create($this->operation('op-once', 'secret-four'));
        $fingerprint = hash('sha256', 'request');
        $first = $this->store->consume('op-once', 'secret-four', 'tenant-a', 'actor-a', 'key-d', $fingerprint, 101);
        $second = $this->store->consume('op-once', 'secret-four', 'tenant-a', 'actor-a', 'key-d', $fingerprint, 101);

        self::assertSame(CrmWriteOperationConsumeResult::CONSUMED, $first->outcome());
        self::assertSame(CrmWriteOperationConsumeResult::REPLAYED, $second->outcome());
        $consumedCount = $this->pdo->query(
            "SELECT COUNT(*) FROM crm_write_operations WHERE state = 'consumed'",
        )->fetchColumn();
        self::assertSame(1, (int) $consumedCount);
    }

    public function testAuditContextAndDatabaseDoNotExposeSecrets(): void
    {
        $operation = $this->operation('op-safe', 'raw-confirmation-secret');
        $this->store->create($operation);
        $context = $operation->auditContext();
        $stored = $this->pdo->query(
            "SELECT * FROM crm_write_operations WHERE operation_id = 'op-safe'",
        )->fetch(\PDO::FETCH_ASSOC);

        self::assertArrayNotHasKey('summary', $context);
        self::assertStringNotContainsString('raw-confirmation-secret', json_encode($context, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('raw-confirmation-secret', json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertArrayNotHasKey('payload', $stored);
    }

    private function operation(string $id, string $token, int $createdAt = 100, int $expiresAt = 200): CrmWriteOperation
    {
        return new CrmWriteOperation(
            $id,
            'tenant-a',
            'actor-a',
            'crm.deal.update',
            hash('sha256', 'normalized-payload'),
            'Update one approved field',
            CrmWriteOperation::hashConfirmationToken($token),
            $createdAt,
            $expiresAt,
        );
    }
}
