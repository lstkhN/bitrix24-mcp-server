<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/** Provider-neutral persistence boundary for short-lived OAuth authorization attempts. */
interface OAuthAuthorizationAttemptStore
{
    /** @throws OAuthAuthorizationAttemptStoreException */
    public function create(OAuthAuthorizationAttempt $attempt): OAuthAuthorizationAttempt;

    /**
     * Atomically consumes an unexpired attempt bound to all caller identities.
     *
     * @throws OAuthAuthorizationAttemptRejectedException
     * @throws OAuthAuthorizationAttemptStoreException
     */
    public function consume(
        string $attemptId,
        string $state,
        string $tenantId,
        string $subjectId,
        string $sessionBinding,
        ?int $consumedAt = null,
    ): OAuthAuthorizationAttempt;
}
