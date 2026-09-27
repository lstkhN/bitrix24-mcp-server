<?php

declare(strict_types=1);

namespace Bitrix24\Mcp\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Session\MemorySessionStore;

final class MemorySessionStoreTest extends TestCase
{
    public function testCreateAndGet(): void
    {
        $store = new MemorySessionStore(3600);
        $tokenHash = hash('sha256', 'secret-token');
        $sessionId = $store->create($tokenHash, 'principal-1');

        $this->assertNotEmpty($sessionId);
        $this->assertEquals(64, strlen($sessionId));

        $data = $store->get($sessionId, $tokenHash);
        $this->assertNotNull($data);
        $this->assertSame($tokenHash, $data['tokenHash']);
        $this->assertSame('principal-1', $data['principal']);
        $this->assertFalse($data['initialized']);
        $this->assertFalse($data['revoked']);
    }

    public function testGetRejectsWrongTokenHash(): void
    {
        $store = new MemorySessionStore();
        $sessionId = $store->create(hash('sha256', 'token-a'));
        $this->assertNull($store->get($sessionId, hash('sha256', 'token-b')));
    }

    public function testGetReturnsNullForRevokedSession(): void
    {
        $store = new MemorySessionStore();
        $tokenHash = hash('sha256', 'token');
        $sessionId = $store->create($tokenHash);
        $store->revoke($sessionId, $tokenHash);
        $this->assertNull($store->get($sessionId, $tokenHash));
    }

    public function testSetInitialized(): void
    {
        $store = new MemorySessionStore();
        $tokenHash = hash('sha256', 'token');
        $sessionId = $store->create($tokenHash);
        $store->setInitialized($sessionId, $tokenHash);

        $data = $store->get($sessionId, $tokenHash);
        $this->assertTrue($data['initialized']);
    }

    public function testGetRejectsNotInitializedForGate(): void
    {
        $store = new MemorySessionStore();
        $tokenHash = hash('sha256', 'token');
        $sessionId = $store->create($tokenHash);
        // Not initialized — get still returns data, gate checks initialized flag separately
        $data = $store->get($sessionId, $tokenHash);
        $this->assertFalse($data['initialized']);
    }
}
