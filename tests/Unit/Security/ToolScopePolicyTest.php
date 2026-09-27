<?php

namespace Bitrix24\Mcp\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Bitrix24\Mcp\Security\ToolScopePolicy;

class ToolScopePolicyTest extends TestCase
{
    private ToolScopePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ToolScopePolicy();
    }

    public function testGetScopeForToolReturnsCorrectScope(): void
    {
        $this->assertSame('db:read', $this->policy->getScopeForTool('database_schema'));
        $this->assertSame('files:read', $this->policy->getScopeForTool('file_reader'));
        $this->assertSame('code:read', $this->policy->getScopeForTool('code_search'));
        $this->assertSame('crm:read', $this->policy->getScopeForTool('crm_entity_list'));
        $this->assertSame('bizproc:read', $this->policy->getScopeForTool('bizproc_reader'));
        $this->assertSame('resources:read', $this->policy->getScopeForTool('modules_list'));
        $this->assertSame('tools:list', $this->policy->getScopeForTool('tools:list'));
        $this->assertNull($this->policy->getScopeForTool('unknown_tool'));
    }

    public function testIsAllowedReturnsTrueWhenNoScopesGiven(): void
    {
        $this->assertTrue($this->policy->isAllowed('tools/call', 'database_schema', []));
        $this->assertTrue($this->policy->isAllowed('resources/read', null, []));
    }

    public function testIsAllowedDeniesWhenScopeMissing(): void
    {
        $this->assertFalse($this->policy->isAllowed('tools/call', 'database_schema', ['crm:read']));
        $this->assertFalse($this->policy->isAllowed('resources/read', null, ['db:read']));
    }

    public function testIsAllowedAllowsWithCorrectScope(): void
    {
        $this->assertTrue($this->policy->isAllowed('tools/call', 'database_schema', ['db:read']));
        $this->assertTrue($this->policy->isAllowed('tools/call', 'file_reader', ['files:read']));
        $this->assertTrue($this->policy->isAllowed('tools/call', 'code_search', ['code:read']));
        $this->assertTrue($this->policy->isAllowed('tools/call', 'bizproc_reader', ['bizproc:read']));
    }

    public function testFilterToolsRemovesDeniedTools(): void
    {
        $tools = [
            'database_schema' => new \Bitrix24\Mcp\Tools\DatabaseSchema(),
            'file_reader' => new \Bitrix24\Mcp\Tools\FileReader(),
        ];

        $filtered = $this->policy->filterTools($tools, ['db:read']);
        $this->assertArrayHasKey('database_schema', $filtered);
        $this->assertArrayNotHasKey('file_reader', $filtered);
    }

    public function testFilterResourcesDeniesWithoutScope(): void
    {
        $resources = [
            'bitrix://project/structure' => new \Bitrix24\Mcp\Resources\ProjectStructure(),
        ];

        $this->assertEmpty($this->policy->filterResources($resources, ['db:read']));
        $this->assertNotEmpty($this->policy->filterResources($resources, ['resources:read']));
    }

    public function testResolveScopesFromJson(): void
    {
        $this->assertEquals(
            ['db:read', 'crm:read'],
            ToolScopePolicy::resolveScopes('["db:read","crm:read"]')
        );
    }

    public function testResolveScopesFromCommaList(): void
    {
        $this->assertEquals(
            ['db:read', 'crm:read'],
            ToolScopePolicy::resolveScopes('db:read, crm:read')
        );
    }

    public function testResolveScopesReturnsEmptyForEmptyString(): void
    {
        $this->assertSame([], ToolScopePolicy::resolveScopes(''));
        $this->assertSame([], ToolScopePolicy::resolveScopes('  '));
    }
}
