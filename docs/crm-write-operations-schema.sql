-- Provider-neutral confirmation persistence. Portable baseline for SQLite and MySQL 8+.
-- Apply via the application's migration mechanism before PdoCrmWriteOperationStore.
-- All timestamps are UTC epoch seconds supplied by the application.
-- Raw confirmation tokens, idempotency keys, CRM payloads, REST URLs and SQL are never stored.
CREATE TABLE crm_write_operations (
    operation_id VARCHAR(255) NOT NULL PRIMARY KEY,
    tenant_id VARCHAR(255) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    operation VARCHAR(255) NOT NULL,
    normalized_payload_hash CHAR(64) NOT NULL,
    summary TEXT NOT NULL,
    confirmation_token_hash CHAR(64) NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    state VARCHAR(16) NOT NULL,
    consumed_at BIGINT NULL,
    idempotency_key_hash CHAR(64) NULL,
    request_fingerprint CHAR(64) NULL
);

CREATE INDEX crm_write_operations_expiry_idx
    ON crm_write_operations (state, expires_at);

-- The logical operation is part of the scope so a key cannot cross tenant,
-- actor or operation boundaries. request_fingerprint permits only exact replay.
CREATE TABLE crm_write_operation_idempotency (
    tenant_id VARCHAR(255) NOT NULL,
    actor_id VARCHAR(255) NOT NULL,
    operation VARCHAR(255) NOT NULL,
    idempotency_key_hash CHAR(64) NOT NULL,
    request_fingerprint CHAR(64) NOT NULL,
    operation_id VARCHAR(255) NOT NULL,
    created_at BIGINT NOT NULL,
    PRIMARY KEY (tenant_id, actor_id, operation, idempotency_key_hash)
);
