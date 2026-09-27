<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tests\Support\CrmWriteContractFixture;

/**
 * Bitrix-independent contract tests for docs/crm-write-dry-run.md.
 *
 * The fixture is deliberately test-only: production CRM write services do not
 * exist yet, and these tests must not be interpreted as an implementation.
 */
final class CrmWriteDryRunContractTest extends TestCase
{
    public function testPreviewIsAlwaysDryRunAndNeverMutates(): void
    {
        $workflow = new CrmWriteContractFixture();

        $preview = $workflow->preview('user-1', 'tenant-a', 'crm.deal.update', $this->target(), ['STAGE_ID' => 'WON']);

        self::assertSame('awaiting_confirmation', $preview['status']);
        self::assertTrue($preview['dry_run']);
        self::assertSame(0, $workflow->mutationCount());
        self::assertSame('validation_error', $workflow->preview('user-1', 'tenant-a', 'crm.deal.update', $this->target(), [], false)['code']);
        self::assertSame(0, $workflow->mutationCount());
    }

    public function testConfirmationIsBoundToActorTenantAndShortTtl(): void
    {
        $workflow = new CrmWriteContractFixture();
        $preview = $this->preview($workflow);

        self::assertSame('confirmation_mismatch', $workflow->execute('user-2', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'key-actor')['code']);
        self::assertSame('confirmation_mismatch', $workflow->execute('user-1', 'tenant-b', $preview['operation_id'], $preview['confirmation_token'], 'key-tenant')['code']);
        self::assertSame(0, $workflow->mutationCount());

        $workflow->advance(300);
        self::assertSame('confirmation_expired', $workflow->execute('user-1', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'key-expired')['code']);
        self::assertSame(0, $workflow->mutationCount());
    }

    public function testConfirmationTokenIsOneTimeButIdempotentDeliveryReplays(): void
    {
        $workflow = new CrmWriteContractFixture();
        $preview = $this->preview($workflow);

        $success = $workflow->execute('user-1', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'same-key');
        $replay = $workflow->execute('user-1', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'same-key');

        self::assertSame('succeeded', $success['status']);
        self::assertSame('replayed', $replay['status']);
        self::assertTrue($replay['replayed']);
        self::assertSame(1, $workflow->mutationCount());
        self::assertSame('confirmation_already_used', $workflow->execute('user-1', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'new-key')['code']);
    }

    public function testIdempotencyKeyConflictsWhenBoundPlanOrConfirmationDiffers(): void
    {
        $workflow = new CrmWriteContractFixture();
        $first = $this->preview($workflow);
        self::assertSame('succeeded', $workflow->execute('user-1', 'tenant-a', $first['operation_id'], $first['confirmation_token'], 'shared-key')['status']);

        $second = $workflow->preview('user-1', 'tenant-a', 'crm.deal.update', ['entity_type' => 'deal', 'entity_id' => 124], ['STAGE_ID' => 'WON']);
        $conflict = $workflow->execute('user-1', 'tenant-a', $second['operation_id'], $second['confirmation_token'], 'shared-key');

        self::assertSame('idempotency_conflict', $conflict['code']);

        $differentConfirmation = $this->preview($workflow);
        self::assertSame(
            'idempotency_conflict',
            $workflow->execute('user-1', 'tenant-a', $differentConfirmation['operation_id'], $differentConfirmation['confirmation_token'], 'shared-key')['code']
        );
        self::assertSame(1, $workflow->mutationCount());
    }

    public function testAuditRedactsTokensSecretsPiiAndUnknownPayloadFields(): void
    {
        $workflow = new CrmWriteContractFixture();
        $preview = $workflow->preview('user-1', 'tenant-a', 'crm.deal.update', $this->target(), [
            'STAGE_ID' => 'WON',
            'EMAIL' => 'person@example.test',
            'PHONE' => '+79990000000',
            'COMMENT' => 'private note',
            'WEBHOOK_URL' => 'https://secret.example.test/hook',
            'ACCESS_TOKEN' => 'access-secret',
        ]);
        $workflow->execute('user-1', 'tenant-a', $preview['operation_id'], $preview['confirmation_token'], 'audit-key');

        $audit = json_encode($workflow->auditEvents(), JSON_THROW_ON_ERROR);
        foreach (['confirmation-secret', 'person@example.test', '+79990000000', 'private note', 'secret.example.test', 'access-secret', 'audit-key'] as $secret) {
            self::assertStringNotContainsString($secret, $audit);
        }
        self::assertSame(['STAGE_ID'], $workflow->auditEvents()[0]['changed_fields']);
    }

    /** @return array<string, mixed> */
    private function preview(CrmWriteContractFixture $workflow): array
    {
        return $workflow->preview('user-1', 'tenant-a', 'crm.deal.update', $this->target(), ['STAGE_ID' => 'WON']);
    }

    /** @return array{entity_type: string, entity_id: int} */
    private function target(): array
    {
        return ['entity_type' => 'deal', 'entity_id' => 123];
    }
}
