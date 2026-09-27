<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * PDO storage for oauth_authorization_attempts. It has no HTTP or provider logic.
 */
final class PdoOAuthAuthorizationAttemptStore implements OAuthAuthorizationAttemptStore
{
    private const AAD_SCHEMA_VERSION = 'oauth-authorization-attempt-v1';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TokenEncryptor $encryptor,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function create(OAuthAuthorizationAttempt $attempt): OAuthAuthorizationAttempt
    {
        if ($attempt->consumedAt() !== null) {
            throw new \InvalidArgumentException('A new OAuth authorization attempt cannot already be consumed.');
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO oauth_authorization_attempts ('
                . 'attempt_id, state_hash, pkce_verifier_ciphertext, tenant_id, subject_id, session_binding, '
                . 'redirect_uri, scopes_json, created_at, expires_at, consumed_at'
                . ') VALUES ('
                . ':attempt_id, :state_hash, :pkce_verifier_ciphertext, :tenant_id, :subject_id, :session_binding, '
                . ':redirect_uri, :scopes_json, :created_at, :expires_at, :consumed_at'
                . ')'
            );
            $statement->execute([
                ':attempt_id' => $attempt->attemptId(),
                ':state_hash' => $attempt->stateHash(),
                ':pkce_verifier_ciphertext' => $this->encryptor->encrypt(
                    $attempt->pkceVerifier(),
                    $this->aad(
                        $attempt->attemptId(),
                        $attempt->tenantId(),
                        $attempt->subjectId(),
                        $attempt->sessionBinding(),
                    ),
                ),
                ':tenant_id' => $attempt->tenantId(),
                ':subject_id' => $attempt->subjectId(),
                ':session_binding' => $attempt->sessionBinding(),
                ':redirect_uri' => $attempt->redirectUri(),
                ':scopes_json' => json_encode($attempt->scopes(), JSON_THROW_ON_ERROR),
                ':created_at' => $attempt->createdAt(),
                ':expires_at' => $attempt->expiresAt(),
                ':consumed_at' => null,
            ]);
        } catch (\PDOException | \JsonException $exception) {
            throw new OAuthAuthorizationAttemptStoreException(
                'OAuth authorization attempt could not be stored.',
                0,
                $exception,
            );
        }

        return $attempt;
    }

    public function consume(
        string $attemptId,
        string $state,
        string $tenantId,
        string $subjectId,
        string $sessionBinding,
        ?int $consumedAt = null,
    ): OAuthAuthorizationAttempt {
        $this->assertBindings($attemptId, $tenantId, $subjectId, $sessionBinding);
        $consumedAt ??= time();
        if ($consumedAt <= 0) {
            throw new \InvalidArgumentException('OAuth authorization attempt consumption time must be positive.');
        }

        try {
            $statement = $this->pdo->prepare(
                'UPDATE oauth_authorization_attempts SET consumed_at = :consumed_at '
                . 'WHERE attempt_id = :attempt_id AND state_hash = :state_hash '
                . 'AND tenant_id = :tenant_id AND subject_id = :subject_id '
                . 'AND session_binding = :session_binding AND consumed_at IS NULL '
                . 'AND expires_at > :consumed_at'
            );
            $statement->execute([
                ':consumed_at' => $consumedAt,
                ':attempt_id' => $attemptId,
                ':state_hash' => OAuthAuthorizationAttempt::hashState($state),
                ':tenant_id' => $tenantId,
                ':subject_id' => $subjectId,
                ':session_binding' => $sessionBinding,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new OAuthAuthorizationAttemptRejectedException('OAuth authorization attempt was rejected.');
            }

            $statement = $this->pdo->prepare(
                'SELECT attempt_id, state_hash, pkce_verifier_ciphertext, tenant_id, subject_id, session_binding, '
                . 'redirect_uri, scopes_json, created_at, expires_at, consumed_at '
                . 'FROM oauth_authorization_attempts WHERE attempt_id = :attempt_id '
                . 'AND tenant_id = :tenant_id AND subject_id = :subject_id AND session_binding = :session_binding '
                . 'AND consumed_at = :consumed_at'
            );
            $statement->execute([
                ':attempt_id' => $attemptId,
                ':tenant_id' => $tenantId,
                ':subject_id' => $subjectId,
                ':session_binding' => $sessionBinding,
                ':consumed_at' => $consumedAt,
            ]);
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new OAuthAuthorizationAttemptStoreException('OAuth authorization attempt could not be loaded.');
            }

            return $this->attemptFromRow($row);
        } catch (
            OAuthAuthorizationAttemptRejectedException | OAuthAuthorizationAttemptStoreException $exception
        ) {
            throw $exception;
        } catch (\PDOException $exception) {
            throw new OAuthAuthorizationAttemptStoreException(
                'OAuth authorization attempt could not be consumed.',
                0,
                $exception,
            );
        }
    }

    private function aad(string $attemptId, string $tenantId, string $subjectId, string $sessionBinding): string
    {
        return self::AAD_SCHEMA_VERSION . ':' . base64_encode(json_encode([
            'attempt_id' => $attemptId,
            'tenant_id' => $tenantId,
            'subject_id' => $subjectId,
            'session_binding' => $sessionBinding,
            'field' => 'pkce_verifier',
        ], JSON_THROW_ON_ERROR));
    }

    private function assertBindings(
        string $attemptId,
        string $tenantId,
        string $subjectId,
        string $sessionBinding,
    ): void {
        foreach ([$attemptId, $tenantId, $subjectId, $sessionBinding] as $binding) {
            if ($binding === '' || strlen($binding) > 255) {
                throw new \InvalidArgumentException(
                    'OAuth authorization attempt bindings must be non-empty strings up to 255 bytes.',
                );
            }
        }
    }

    /** @param array<string, mixed> $row */
    private function attemptFromRow(array $row): OAuthAuthorizationAttempt
    {
        try {
            $scopes = json_decode((string) $row['scopes_json'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($scopes)) {
                throw new \JsonException('Scopes must be an array.');
            }
            return new OAuthAuthorizationAttempt(
                (string) $row['attempt_id'],
                (string) $row['state_hash'],
                $this->encryptor->decrypt(
                    (string) $row['pkce_verifier_ciphertext'],
                    $this->aad(
                        (string) $row['attempt_id'],
                        (string) $row['tenant_id'],
                        (string) $row['subject_id'],
                        (string) $row['session_binding'],
                    ),
                ),
                (string) $row['tenant_id'],
                (string) $row['subject_id'],
                (string) $row['session_binding'],
                (string) $row['redirect_uri'],
                $scopes,
                (int) $row['created_at'],
                (int) $row['expires_at'],
                $row['consumed_at'] === null ? null : (int) $row['consumed_at'],
            );
        } catch (\JsonException | \InvalidArgumentException $exception) {
            throw new OAuthAuthorizationAttemptStoreException(
                'Stored OAuth authorization attempt is invalid.',
                0,
                $exception,
            );
        }
    }
}
