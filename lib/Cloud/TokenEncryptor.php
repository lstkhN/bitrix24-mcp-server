<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Cloud;

/**
 * Encrypts opaque OAuth tokens while binding them to caller-supplied context.
 */
interface TokenEncryptor
{
    /**
     * @throws TokenEncryptionException
     */
    public function encrypt(string $plaintext, string $aad): string;

    /**
     * @throws TokenEncryptionException
     */
    public function decrypt(string $ciphertext, string $aad): string;
}
