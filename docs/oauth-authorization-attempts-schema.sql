-- Portable baseline for SQLite and MySQL 8+.
-- Apply through the application's migration mechanism before constructing
-- PdoOAuthAuthorizationAttemptStore. Epoch fields are application-supplied UTC seconds.
-- state_hash is SHA-256 hex; raw OAuth state and raw PKCE verifier are never stored.
CREATE TABLE oauth_authorization_attempts (
    attempt_id VARCHAR(255) NOT NULL PRIMARY KEY,
    state_hash CHAR(64) NOT NULL,
    pkce_verifier_ciphertext TEXT NOT NULL,
    tenant_id VARCHAR(255) NOT NULL,
    subject_id VARCHAR(255) NOT NULL,
    session_binding VARCHAR(255) NOT NULL,
    redirect_uri VARCHAR(2048) NOT NULL,
    scopes_json TEXT NOT NULL,
    created_at BIGINT NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed_at BIGINT NULL
);
