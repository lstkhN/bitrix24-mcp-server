<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\CrmAuditEvent;
use Bitrix24\Mcp\Tools\CrmAuditEventStore;
use Bitrix24\Mcp\Tools\PdoCrmAuditEventStore;

final class PdoCrmAuditEventStoreTest extends TestCase
{
    private \PDO $pdo;
    private PdoCrmAuditEventStore $store;

    protected function setUp(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite driver is unavailable; CRM audit store tests require sqlite::memory:.');
        }
        $this->pdo = new \PDO('sqlite::memory:');
        $schema = dirname(__DIR__, 3) . '/docs/crm-audit-events-schema.sql';
        $this->pdo->exec((string) file_get_contents($schema));
        $this->store = new PdoCrmAuditEventStore($this->pdo);
    }

    #[DataProvider('eventTypes')]
    public function testAppendsEverySupportedEventType(string $eventType): void
    {
        $event = $this->event('event-' . $eventType, $eventType);
        $this->store->append($event);

        $stored = $this->pdo->query(
            "SELECT * FROM crm_audit_events WHERE event_id = 'event-" . $eventType . "'",
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('tenant-a', $stored['tenant_id']);
        self::assertSame('actor-a', $stored['actor_id']);
        self::assertSame('op-a', $stored['operation_id']);
        self::assertSame('crm.deal.update', $stored['operation']);
        self::assertSame($eventType, $stored['event_type']);
        self::assertSame($event->payload(), json_decode($stored['payload_json'], true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{string}> */
    public static function eventTypes(): array
    {
        return [
            'preview' => [CrmAuditEvent::PREVIEW_CREATED],
            'consumed' => [CrmAuditEvent::CONFIRMATION_CONSUMED],
            'replayed' => [CrmAuditEvent::REPLAYED],
            'conflict' => [CrmAuditEvent::CONFLICT],
            'expired' => [CrmAuditEvent::EXPIRED],
            'rejected' => [CrmAuditEvent::REJECTED],
            'started' => [CrmAuditEvent::EXECUTE_STARTED],
            'succeeded' => [CrmAuditEvent::SUCCEEDED],
            'failed' => [CrmAuditEvent::FAILED],
            'unknown' => [CrmAuditEvent::UNKNOWN],
        ];
    }

    public function testPayloadRejectsSecretsAndUnallowlistedContext(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrmAuditEvent(
            'event-unsafe',
            100,
            'tenant-a',
            'actor-a',
            'op-a',
            'crm.deal.update',
            CrmAuditEvent::FAILED,
            ['confirmation_token' => 'raw-confirmation-secret'],
        );
    }

    public function testPayloadRejectsUnredactedTextEvenForAllowedField(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CrmAuditEvent(
            'event-unsafe-value',
            100,
            'tenant-a',
            'actor-a',
            'op-a',
            'crm.deal.update',
            CrmAuditEvent::FAILED,
            ['error_code' => 'customer@example.test'],
        );
    }

    public function testStableEventIdCannotBeAppendedTwiceAndErrorIsSafe(): void
    {
        $event = $this->event('event-stable');
        $this->store->append($event);
        try {
            $this->store->append($event);
            self::fail('Expected duplicate stable event ID to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('CRM audit event could not be appended.', $exception->getMessage());
            self::assertStringNotContainsString('event-stable', $exception->getMessage());
        }
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM crm_audit_events')->fetchColumn());
    }

    public function testBindingsArePreparedAndStoreExposesOnlyAppend(): void
    {
        $event = $this->event('event-bound', CrmAuditEvent::PREVIEW_CREATED, "actor-a' OR 1=1 --");
        $this->store->append($event);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM crm_audit_events')->fetchColumn());
        $interface = new \ReflectionClass(CrmAuditEventStore::class);
        $methodNames = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            $interface->getMethods(),
        );
        self::assertSame(['append'], $methodNames);
    }

    private function event(
        string $eventId,
        string $eventType = CrmAuditEvent::PREVIEW_CREATED,
        string $actorId = 'actor-a',
    ): CrmAuditEvent {
        return new CrmAuditEvent(
            $eventId,
            100,
            'tenant-a',
            $actorId,
            'op-a',
            'crm.deal.update',
            $eventType,
            [
                'normalized_payload_hash' => hash('sha256', 'normalized-payload'),
                'idempotency_key_fingerprint' => '0123456789ab',
                'policy_version' => 'policy-v1',
                'attempt_count' => 0,
            ],
        );
    }
}
