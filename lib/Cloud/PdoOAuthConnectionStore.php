<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * PDO implementation backed by the oauth_connections schema.
 *
 * It deliberately has no OAuth HTTP behaviour. Every read or mutation is
 * bound to tenant, subject, and connection identifiers.
 */
final class PdoOAuthConnectionStore implements OAuthConnectionStore
{
    private const AAD_SCHEMA_VERSION = 'oauth-connection-v1';

    public function __construct(
        private readonly \PDO $pdo,
        private readonly TokenEncryptor $encryptor,
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function createConnection(OAuthConnection $connection, OAuthConnectionTokens $tokens): OAuthConnection
    {
        if ($connection->status() !== OAuthConnection::STATUS_ACTIVE || $connection->version() !== 0) {
            throw new \InvalidArgumentException('New OAuth connections must be active and have version zero.');
        }

        $now = time();
        $createdAt = $connection->createdAt() ?? $now;
        $updatedAt = $connection->updatedAt() ?? $createdAt;
        $statement = $this->pdo->prepare(
            'INSERT INTO oauth_connections ('
            . 'tenant_id, subject_id, connection_id, scopes_json, status, expires_at, version, '
            . 'access_token_ciphertext, refresh_token_ciphertext, created_at, updated_at, revoked_at'
            . ') VALUES ('
            . ':tenant_id, :subject_id, :connection_id, :scopes_json, :status, :expires_at, :version, '
            . ':access_token_ciphertext, :refresh_token_ciphertext, :created_at, :updated_at, :revoked_at'
            . ')'
        );
        $statement->execute($this->parameters($connection, $tokens, $createdAt, $updatedAt));

        return new OAuthConnection(
            $connection->tenantId(),
            $connection->subjectId(),
            $connection->connectionId(),
            $connection->scopes(),
            $connection->expiresAt(),
            $connection->status(),
            0,
            $createdAt,
            $updatedAt,
        );
    }

    public function getConnection(string $tenantId, string $subjectId, string $connectionId): ?OAuthConnection
    {
        $statement = $this->pdo->prepare(
            'SELECT tenant_id, subject_id, connection_id, scopes_json, status, expires_at, version, '
            . 'created_at, updated_at, revoked_at FROM oauth_connections '
            . 'WHERE tenant_id = :tenant_id AND subject_id = :subject_id AND connection_id = :connection_id'
        );
        $statement->execute($this->identifiers($tenantId, $subjectId, $connectionId));
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $this->connectionFromRow($row);
    }

    public function getActiveTokens(string $tenantId, string $subjectId, string $connectionId): ?OAuthConnectionTokens
    {
        $statement = $this->pdo->prepare(
            'SELECT access_token_ciphertext, refresh_token_ciphertext FROM oauth_connections '
            . 'WHERE tenant_id = :tenant_id AND subject_id = :subject_id '
            . 'AND connection_id = :connection_id AND status = :status'
        );
        $parameters = $this->identifiers($tenantId, $subjectId, $connectionId);
        $parameters[':status'] = OAuthConnection::STATUS_ACTIVE;
        $statement->execute($parameters);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new OAuthConnectionTokens(
            $this->encryptor->decrypt(
                (string) $row['access_token_ciphertext'],
                $this->aad($tenantId, $subjectId, $connectionId, 'access'),
            ),
            $row['refresh_token_ciphertext'] === null ? null : $this->encryptor->decrypt(
                (string) $row['refresh_token_ciphertext'],
                $this->aad($tenantId, $subjectId, $connectionId, 'refresh'),
            ),
        );
    }

    public function compareAndSwapTokens(
        string $tenantId,
        string $subjectId,
        string $connectionId,
        int $expectedVersion,
        OAuthConnectionTokens $tokens,
        ?int $expiresAt,
    ): bool {
        if ($expectedVersion < 0) {
            throw new \InvalidArgumentException('Expected OAuth connection version cannot be negative.');
        }

        $statement = $this->pdo->prepare(
            'UPDATE oauth_connections SET access_token_ciphertext = :access_token_ciphertext, '
            . 'refresh_token_ciphertext = :refresh_token_ciphertext, expires_at = :expires_at, '
            . 'version = version + 1, updated_at = :updated_at WHERE tenant_id = :tenant_id '
            . 'AND subject_id = :subject_id AND connection_id = :connection_id '
            . 'AND status = :status AND version = :expected_version'
        );
        $parameters = $this->identifiers($tenantId, $subjectId, $connectionId);
        $parameters += [
            ':access_token_ciphertext' => $this->encryptor->encrypt(
                $tokens->accessToken(),
                $this->aad($tenantId, $subjectId, $connectionId, 'access'),
            ),
            ':refresh_token_ciphertext' => $tokens->refreshToken() === null ? null : $this->encryptor->encrypt(
                $tokens->refreshToken(),
                $this->aad($tenantId, $subjectId, $connectionId, 'refresh'),
            ),
            ':expires_at' => $expiresAt,
            ':updated_at' => time(),
            ':status' => OAuthConnection::STATUS_ACTIVE,
            ':expected_version' => $expectedVersion,
        ];
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    public function revokeConnection(string $tenantId, string $subjectId, string $connectionId, int $revokedAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE oauth_connections SET status = :new_status, revoked_at = :revoked_at, '
            . 'updated_at = :updated_at, version = version + 1 WHERE tenant_id = :tenant_id '
            . 'AND subject_id = :subject_id AND connection_id = :connection_id '
            . 'AND status <> :current_status'
        );
        $parameters = $this->identifiers($tenantId, $subjectId, $connectionId);
        $parameters += [
            ':new_status' => OAuthConnection::STATUS_REVOKED,
            ':current_status' => OAuthConnection::STATUS_REVOKED,
            ':revoked_at' => $revokedAt,
            ':updated_at' => time(),
        ];
        $statement->execute($parameters);

        return $statement->rowCount() === 1;
    }

    public function deleteConnection(string $tenantId, string $subjectId, string $connectionId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM oauth_connections WHERE tenant_id = :tenant_id AND subject_id = :subject_id '
            . 'AND connection_id = :connection_id'
        );
        $statement->execute($this->identifiers($tenantId, $subjectId, $connectionId));

        return $statement->rowCount() === 1;
    }

    /** @return array<string, mixed> */
    private function parameters(
        OAuthConnection $connection,
        OAuthConnectionTokens $tokens,
        int $createdAt,
        int $updatedAt,
    ): array {
        $parameters = $this->identifiers(
            $connection->tenantId(),
            $connection->subjectId(),
            $connection->connectionId(),
        );
        $parameters += [
            ':scopes_json' => json_encode($connection->scopes(), JSON_THROW_ON_ERROR),
            ':status' => $connection->status(),
            ':expires_at' => $connection->expiresAt(),
            ':version' => 0,
            ':access_token_ciphertext' => $this->encryptor->encrypt(
                $tokens->accessToken(),
                $this->aad($connection->tenantId(), $connection->subjectId(), $connection->connectionId(), 'access'),
            ),
            ':refresh_token_ciphertext' => $tokens->refreshToken() === null ? null : $this->encryptor->encrypt(
                $tokens->refreshToken(),
                $this->aad($connection->tenantId(), $connection->subjectId(), $connection->connectionId(), 'refresh'),
            ),
            ':created_at' => $createdAt,
            ':updated_at' => $updatedAt,
            ':revoked_at' => null,
        ];

        return $parameters;
    }

    /** @return array<string, string> */
    private function identifiers(string $tenantId, string $subjectId, string $connectionId): array
    {
        foreach ([$tenantId, $subjectId, $connectionId] as $identifier) {
            if ($identifier === '' || strlen($identifier) > 255) {
                throw new \InvalidArgumentException(
                    'OAuth connection identifiers must be non-empty strings up to 255 bytes.',
                );
            }
        }

        return [
            ':tenant_id' => $tenantId,
            ':subject_id' => $subjectId,
            ':connection_id' => $connectionId,
        ];
    }

    private function aad(string $tenantId, string $subjectId, string $connectionId, string $field): string
    {
        return self::AAD_SCHEMA_VERSION . ':' . base64_encode(json_encode([
            'tenant_id' => $tenantId,
            'subject_id' => $subjectId,
            'connection_id' => $connectionId,
            'field' => $field,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $row */
    private function connectionFromRow(array $row): OAuthConnection
    {
        try {
            $scopes = json_decode((string) $row['scopes_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException('Stored OAuth connection scopes are invalid.', 0, $exception);
        }
        if (!is_array($scopes)) {
            throw new \UnexpectedValueException('Stored OAuth connection scopes are invalid.');
        }

        return new OAuthConnection(
            (string) $row['tenant_id'],
            (string) $row['subject_id'],
            (string) $row['connection_id'],
            $scopes,
            $row['expires_at'] === null ? null : (int) $row['expires_at'],
            (string) $row['status'],
            (int) $row['version'],
            (int) $row['created_at'],
            (int) $row['updated_at'],
            $row['revoked_at'] === null ? null : (int) $row['revoked_at'],
        );
    }
}
