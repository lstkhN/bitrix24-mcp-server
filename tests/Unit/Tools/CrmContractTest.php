<?php

namespace Bitrix24\Mcp\Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Tools\CrmActivityList;
use Bitrix24\Mcp\Tools\CrmEntityGet;
use Bitrix24\Mcp\Tools\CrmEntityList;
use Bitrix24\Mcp\Tools\CrmSchema;
use Bitrix24\Mcp\Tools\CrmTimelineList;

/**
 * Контракт CRM-инструментов, не требующий установленного Bitrix CRM.
 *
 * Проверка исходников CHECK_PERMISSIONS намеренно находится здесь: unit-тесты
 * не должны подменять механизм авторизации Bitrix собственными стабами.
 */
final class CrmContractTest extends TestCase
{
    /**
     * @return array<string, array{class: class-string, name: string}>
     */
    public static function crmTools(): array
    {
        return [
            'schema' => ['class' => CrmSchema::class, 'name' => 'crm_schema'],
            'entity list' => ['class' => CrmEntityList::class, 'name' => 'crm_entity_list'],
            'entity get' => ['class' => CrmEntityGet::class, 'name' => 'crm_entity_get'],
            'activity list' => ['class' => CrmActivityList::class, 'name' => 'crm_activity_list'],
            'timeline list' => ['class' => CrmTimelineList::class, 'name' => 'crm_timeline_list'],
        ];
    }

    /**
     * @dataProvider crmTools
     * @param class-string $class
     */
    public function testToolNamesAreStableAndDescriptionsAreReadOnly(string $class, string $name): void
    {
        $tool = new $class();

        self::assertSame($name, $tool->getName());
        if ($class !== CrmSchema::class) {
            self::assertMatchesRegularExpression('/(чтени|read[- ]?only)/iu', $tool->getDescription());
        }
        self::assertDoesNotMatchRegularExpression('/(создан|изменен|удален|create|update|delete)/iu', $tool->getDescription());
    }

    public function testEntityListSchemaHasRequiredEntityAndBoundedLimit(): void
    {
        $schema = (new CrmEntityList())->getInputSchema();

        self::assertSame(['entity'], $schema['required']);
        self::assertSame(['lead', 'deal', 'contact', 'company'], $schema['properties']['entity']['enum']);
        self::assertSame(1, $schema['properties']['limit']['minimum']);
        self::assertSame(100, $schema['properties']['limit']['maximum']);
        self::assertSame(50, $schema['properties']['limit']['default']);
    }

    public function testEntityGetSchemaHasRequiredEntityAndId(): void
    {
        $schema = (new CrmEntityGet())->getInputSchema();

        self::assertSame(['entity', 'id'], $schema['required']);
        self::assertSame('integer', $schema['properties']['id']['type']);
        self::assertSame(1, $schema['properties']['id']['minimum']);
        self::assertSame(['lead', 'deal', 'contact', 'company'], $schema['properties']['entity']['enum']);
    }

    public function testSchemaToolHasRequiredEntityAndKnownEntities(): void
    {
        $schema = (new CrmSchema())->getInputSchema();

        self::assertSame(['entity'], $schema['required']);
        self::assertSame(['lead', 'deal', 'contact', 'company', 'quote'], $schema['properties']['entity']['enum']);
        self::assertSame('boolean', $schema['properties']['include_fields']['type']);
        self::assertSame('boolean', $schema['properties']['include_uf_fields']['type']);
    }

    /**
     * @dataProvider listToolProvider
     * @param class-string $class
     */
    public function testListToolsExposeTheSameSafeLimitContract(string $class): void
    {
        $limit = (new $class())->getInputSchema()['properties']['limit'];

        self::assertSame('integer', $limit['type']);
        self::assertSame(1, $limit['minimum']);
        self::assertSame(100, $limit['maximum']);
        self::assertSame(50, $limit['default']);
    }

    /**
     * @return array<string, array{class: class-string}>
     */
    public static function listToolProvider(): array
    {
        return [
            'entities' => ['class' => CrmEntityList::class],
            'activities' => ['class' => CrmActivityList::class],
            'timeline' => ['class' => CrmTimelineList::class],
        ];
    }

    public function testActivitySchemaAllowsOptionalOwnerFilters(): void
    {
        $schema = (new CrmActivityList())->getInputSchema();

        self::assertArrayNotHasKey('required', $schema);
        self::assertSame(1, $schema['properties']['owner_id']['minimum']);
        self::assertSame(['lead', 'deal', 'contact', 'company'], $schema['properties']['entity']['enum']);
    }

    public function testTimelineSchemaRequiresEntityAndOwnerId(): void
    {
        $schema = (new CrmTimelineList())->getInputSchema();

        self::assertSame(['entity', 'owner_id'], $schema['required']);
        self::assertSame(1, $schema['properties']['owner_id']['minimum']);
    }

    public function testReadOnlyEntityQueriesKeepBitrixPermissionChecksEnabled(): void
    {
        $entityList = $this->readToolSource(CrmEntityList::class);
        $entityGet = $this->readToolSource(CrmEntityGet::class);
        $activityList = $this->readToolSource(CrmActivityList::class);
        $timelineList = $this->readToolSource(CrmTimelineList::class);

        self::assertStringContainsString("\$filter['CHECK_PERMISSIONS'] = 'Y';", $entityList);
        self::assertStringContainsString("\$filter['CHECK_PERMISSIONS'] = 'Y';", $activityList);
        self::assertStringContainsString('GetByID($ownerId, true, [\'ID\'])', $timelineList);
        // CrmEntityGet must NOT disable permission checks (second argument must be true, not false).
        self::assertStringContainsString('GetByID($id, true, $select)', $entityGet);
        self::assertStringNotContainsString('GetByID($id, false', $entityGet);
    }

    /**
     * @param class-string $toolClass
     */
    private function readToolSource(string $toolClass): string
    {
        $file = dirname(__DIR__, 3) . '/lib/Tools/' . (new \ReflectionClass($toolClass))->getShortName() . '.php';
        $source = file_get_contents($file);

        self::assertIsString($source, 'CRM tool source must be readable');

        return $source;
    }
}
