-- Provider-neutral append-only confirmation audit trail.
-- Portable baseline for SQLite and MySQL 8+.
-- The application role must receive INSERT and SELECT only: never UPDATE or DELETE.
-- event_id is supplied by the caller as a stable opaque ID; no auto-increment ID is used.
-- payload_json contains only CrmAuditEvent's validated hashes and allowlisted redacted metadata.
-- Raw confirmation tokens, idempotency keys, CRM payloads, credentials, URLs and PII are never stored.

CREATE TABLE crm_audit_events (
    event_id VARCHAR(255) NOT NULL PRIMARY KEY,
    occurred_at BIGINT NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    operation_id VARCHAR(255) NOT NULL,
    operation VARCHAR(255) NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    payload_json TEXT NOT NULL
);

CREATE INDEX crm_audit_events_operation_idx
    ON crm_audit_events (tenant_id, actor_id, operation_id, occurred_at);

CREATE INDEX crm_audit_events_type_idx
    ON crm_audit_events (tenant_id, event_type, occurred_at);

-- Deployments must enforce append-only database permissions for the application's
-- credentials. Retention/export jobs use separate, audited credentials.
