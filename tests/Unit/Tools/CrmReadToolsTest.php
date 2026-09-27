<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\CrmEntityGet;
use Bitrix24\Mcp\Tools\CrmEntityList;
use Bitrix24\Mcp\Tools\CrmActivityList;
use Bitrix24\Mcp\Tools\CrmTimelineList;

final class CrmReadToolsTest extends TestCase
{
    public function testListToolExposesReadOnlyEntitySchema(): void
    {
        $schema = (new CrmEntityList())->getInputSchema();

        self::assertContains('entity', $schema['required']);
        self::assertSame(['lead', 'deal', 'contact', 'company'], $schema['properties']['entity']['enum']);
        self::assertSame(100, $schema['properties']['limit']['maximum']);
    }

    public function testGetToolRequiresPositiveEntityId(): void
    {
        $schema = (new CrmEntityGet())->getInputSchema();

        self::assertSame(['entity', 'id'], $schema['required']);
        self::assertSame(1, $schema['properties']['id']['minimum']);
    }

    public function testToolsHaveStableNames(): void
    {
        self::assertSame('crm_entity_list', (new CrmEntityList())->getName());
        self::assertSame('crm_entity_get', (new CrmEntityGet())->getName());
        self::assertSame('crm_activity_list', (new CrmActivityList())->getName());
        self::assertSame('crm_timeline_list', (new CrmTimelineList())->getName());
    }

    public function testActivityToolLimitsResultsAndSupportsOwnerFilters(): void
    {
        $schema = (new CrmActivityList())->getInputSchema();

        self::assertSame(100, $schema['properties']['limit']['maximum']);
        self::assertSame(['lead', 'deal', 'contact', 'company'], $schema['properties']['entity']['enum']);
    }

    public function testTimelineToolRequiresEntityAndOwner(): void
    {
        $schema = (new CrmTimelineList())->getInputSchema();

        self::assertSame(['entity', 'owner_id'], $schema['required']);
        self::assertSame(100, $schema['properties']['limit']['maximum']);
    }

    public function testCrmEntityGetDoesNotDisablePermissionChecksViaFalseArgument(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../lib/Tools/CrmEntityGet.php');

        self::assertIsString($source);
        self::assertStringContainsString('GetByID($id, true, $select)', $source);
        self::assertStringNotContainsString('GetByID($id, false', $source);
    }

    public function testCrmEntityListKeepsCheckPermissionsEnabled(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../lib/Tools/CrmEntityList.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$filter['CHECK_PERMISSIONS'] = 'Y';", $source);
        self::assertStringNotContainsString("\$filter['CHECK_PERMISSIONS'] = 'N';", $source);
    }

    public function testCrmActivityListKeepsCheckPermissionsEnabled(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../lib/Tools/CrmActivityList.php');

        self::assertIsString($source);
        self::assertStringContainsString("\$filter['CHECK_PERMISSIONS'] = 'Y';", $source);
        self::assertStringNotContainsString("\$filter['CHECK_PERMISSIONS'] = 'N';", $source);
    }

    public function testCrmTimelineListKeepsPermissionChecksEnabled(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../lib/Tools/CrmTimelineList.php');

        self::assertIsString($source);
        self::assertStringContainsString('GetByID($ownerId, true, [\'ID\'])', $source);
        self::assertStringNotContainsString('GetByID($ownerId, false', $source);
    }
}
