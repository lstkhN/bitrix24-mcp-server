<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/** PDO append-only audit store; it has no CRM client or execution capability. */
final class PdoCrmAuditEventStore implements CrmAuditEventStore
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function append(CrmAuditEvent $event): void
    {
        try {
            $payload = json_encode($event->payload(), JSON_THROW_ON_ERROR);
            $statement = $this->pdo->prepare(
                'INSERT INTO crm_audit_events ('
                . 'event_id, occurred_at, tenant_id, actor_id, operation_id, operation, event_type, payload_json'
                . ') VALUES ('
                . ':event_id, :occurred_at, :tenant_id, :actor_id, :operation_id, :operation, :event_type, '
                . ':payload_json'
                . ')',
            );
            $statement->execute([
                ':event_id' => $event->eventId(),
                ':occurred_at' => $event->occurredAt(),
                ':tenant_id' => $event->tenantId(),
                ':actor_id' => $event->actorId(),
                ':operation_id' => $event->operationId(),
                ':operation' => $event->operation(),
                ':event_type' => $event->eventType(),
                ':payload_json' => $payload,
            ]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('CRM audit event could not be appended.', 0, $exception);
        }
    }
}
