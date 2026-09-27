<?php

namespace Bitrix24\Mcp\Tests\Integration;

use Bitrix\Main\Loader;
use PHPUnit\Framework\TestCase;

final class BoxModuleLoadTest extends TestCase
{
    public function testModuleLoadsInTargetBitrixRuntime(): void
    {
        if (getenv('BITRIX24_RUN_INTEGRATION') !== '1') {
            self::markTestSkipped('Set BITRIX24_RUN_INTEGRATION=1 to permit live integration tests.');
        }

        $bootstrap = getenv('BITRIX24_BOX_BOOTSTRAP');
        if ($bootstrap === false || $bootstrap === '') {
            self::markTestSkipped('Set BITRIX24_BOX_BOOTSTRAP to run this Box integration test.');
        }

        self::assertFileIsReadable($bootstrap, 'BITRIX24_BOX_BOOTSTRAP must point to a readable Bitrix bootstrap file.');
        require_once $bootstrap;

        self::assertTrue(Loader::includeModule('bitrix24.mcp'));
        self::assertTrue(class_exists(\Bitrix24\Mcp\Server::class));
    }
}
