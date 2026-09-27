<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Result of consuming an OAuth state. Keep the returned verifier for later checks.
 */
final class OAuthStateVerification
{
    public function __construct(
        private readonly bool $accepted,
        private readonly ?OAuthStateVerifier $nextVerifier,
    ) {
    }

    public function isAccepted(): bool
    {
        return $this->accepted;
    }

    /**
     * Returns the immutable verifier with the accepted state marked as consumed.
     *
     * @throws \LogicException when the state was rejected
     */
    public function nextVerifier(): OAuthStateVerifier
    {
        if (!$this->accepted || $this->nextVerifier === null) {
            throw new \LogicException('Rejected OAuth state has no next verifier.');
        }

        return $this->nextVerifier;
    }
}
