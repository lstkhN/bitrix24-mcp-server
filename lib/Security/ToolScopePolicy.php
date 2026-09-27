<?php

namespace Bitrix24\Mcp\Security;

/**
 * Provider-neutral authorization policy mapping MCP tools to scopes.
 * Deny-by-default: if scopes are explicitly passed, any tool/resource
 * without a matching scope is denied.
 */
class ToolScopePolicy
{
    /** @var array<string, string> Tool name -> scope */
    private const TOOL_SCOPE_MAP = [
        'database_schema' => 'db:read',
        'sql_query' => 'db:read',
        'file_reader' => 'files:read',
        'code_search' => 'code:read',
        'code:read' => 'code:read',
        'crm_entity_list' => 'crm:read',
        'crm_entity_get' => 'crm:read',
        'crm_activity_list' => 'crm:read',
        'crm_timeline_list' => 'crm:read',
        'crm_schema' => 'crm:read',
        'modules_list' => 'resources:read',
        'components_list' => 'resources:read',
        'event_handlers' => 'resources:read',
        'bizproc_reader' => 'bizproc:read',
        'class_inspector' => 'resources:read',
        'tools:list' => 'tools:list',
        'resources:read' => 'resources:read',
    ];

    /** @var array<string, string[]> Scope -> allowed methods */
    private const METHOD_SCOPE_MAP = [
        'tools:list' => ['tools:list'],
        'resources:read' => ['resources/list', 'resources/read'],
        'db:read' => ['database_schema', 'sql_query'],
        'files:read' => ['file_reader'],
        'code:read' => ['code_search', 'code:read'],
        'crm:read' => [
            'crm_entity_list', 'crm_entity_get', 'crm_activity_list',
            'crm_timeline_list', 'crm_schema',
        ],
        'bizproc:read' => ['bizproc_reader'],
    ];

    /**
     * Get the required scope for a given MCP tool name.
     *
     * @param string $toolName
     * @return string|null Scope or null if unknown
     */
    public function getScopeForTool(string $toolName): ?string
    {
        return self::TOOL_SCOPE_MAP[$toolName] ?? null;
    }

    /**
     * Check whether a scope set allows a specific MCP method call.
     *
     * @param string $method MCP method (tools/call, resources/read, etc.)
     * @param string $toolName Tool name when applicable
     * @param array<string> $scopes Explicit scope set (deny-by-default if non-empty)
     * @return bool
     */
    public function isAllowed(string $method, ?string $toolName, array $scopes): bool
    {
        if ($scopes === []) {
            // No scopes passed: preserve existing direct/CLI behavior.
            return true;
        }

        $scopeSet = array_flip(array_map('trim', $scopes));

        // Method-level filtering
        $allowedMethods = [];
        foreach ($scopeSet as $scope => $_) {
            $allowedMethods = array_merge(
                $allowedMethods,
                self::METHOD_SCOPE_MAP[$scope] ?? []
            );
        }
        $allowedMethods = array_unique($allowedMethods);

        switch ($method) {
            case 'tools/list':
                return isset($scopeSet['tools:list']);
            case 'tools/call':
                if ($toolName === null) {
                    return false;
                }
                $requiredScope = $this->getScopeForTool($toolName);
                if ($requiredScope === null) {
                    return false;
                }
                return isset($scopeSet[$requiredScope]);
            case 'resources/list':
            case 'resources/read':
                return isset($scopeSet['resources:read']);
            default:
                return false;
        }
    }

    /**
     * Filter registered tools by scope set.
     *
     * @param array<string, \Bitrix24\Mcp\Tools\AbstractTool> $tools
     * @param array<string> $scopes
     * @return array<string, \Bitrix24\Mcp\Tools\AbstractTool>
     */
    public function filterTools(array $tools, array $scopes): array
    {
        if ($scopes === []) {
            return $tools;
        }

        $filtered = [];
        $scopeSet = array_flip(array_map('trim', $scopes));

        foreach ($tools as $name => $tool) {
            $requiredScope = $this->getScopeForTool($name);
            if ($requiredScope !== null && isset($scopeSet[$requiredScope])) {
                $filtered[$name] = $tool;
            } elseif ($requiredScope === null && isset($scopeSet['tools:list'])) {
                // Unknown tools allowed only when tools:list scope is present
                $filtered[$name] = $tool;
            }
        }

        return $filtered;
    }

    /**
     * Filter registered resources by scope set.
     *
     * @param array<string, \Bitrix24\Mcp\Resources\AbstractResource> $resources
     * @param array<string> $scopes
     * @return array<string, \Bitrix24\Mcp\Resources\AbstractResource>
     */
    public function filterResources(array $resources, array $scopes): array
    {
        if ($scopes === []) {
            return $resources;
        }

        $scopeSet = array_flip(array_map('trim', $scopes));

        if (!isset($scopeSet['resources:read'])) {
            return [];
        }

        return $resources;
    }

    /**
     * Resolve scopes from Bitrix option value (JSON array or comma list).
     *
     * @param string $raw Raw option value
     * @return array<string>
     */
    public static function resolveScopes(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        // Try JSON array first
        $decoded = @json_decode($raw, true);
        if (is_array($decoded)) {
            return array_map('trim', $decoded);
        }

        // Comma-separated list fallback
        return array_filter(array_map('trim', explode(',', $raw)), fn($s) => $s !== '');
    }
}
