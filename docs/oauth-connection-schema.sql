-- Portable baseline for SQLite and MySQL 8+.
-- Apply through the application's migration mechanism before constructing
-- PdoOAuthConnectionStore. Epoch fields are application-supplied UTC seconds.
CREATE TABLE oauth_connections (
    tenant_id VARCHAR(255) NOT NULL,
    subject_id VARCHAR(255) NOT NULL,
    connection_id VARCHAR(255) NOT NULL,
    scopes_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL,
    expires_at BIGINT NULL,
    version BIGINT NOT NULL,
    access_token_ciphertext TEXT NOT NULL,
    refresh_token_ciphertext TEXT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL,
    revoked_at BIGINT NULL,
    PRIMARY KEY (tenant_id, subject_id, connection_id)
);
