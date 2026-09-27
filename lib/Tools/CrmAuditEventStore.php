<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tools;

/**
 * Append-only persistence boundary for confirmation audit records.
 *
 * Implementations must only insert immutable events and must not execute CRM
 * operations or accept raw CRM payloads, tokens, credentials, or URLs.
 */
interface CrmAuditEventStore
{
    /** Appends one immutable event. Duplicate stable event IDs are rejected. */
    public function append(CrmAuditEvent $event): void;
}
