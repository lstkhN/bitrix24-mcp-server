# Bitrix24 MCP — autonomous task pool

## Execution policy

- Tasks marked `parallel` touch separate files and may run concurrently.
- Tasks marked `sequential` start only after dependencies are complete.
- Read-only/security tasks must not modify source code.
- Every implementation task must run PHPUnit and PHP lint before completion.
- Destructive CRM operations are out of scope until an explicit confirmation workflow exists.

## Pool

### P0 — completed
- [x] Security audit and sensitive-log redaction
- [x] JSON-RPC protocol validation and notification handling
- [x] CRM read-only entity tools
- [x] CRM activity and timeline read-only tools

### P1 — parallel

- [x] `docs-readme`: document installation, transports, tools, Cursor/Claude configuration and security model. Modify only `README.md` and `docs/`.
- [x] `protocol-tests`: expand protocol/transport tests without changing production code unless a confirmed defect is found. Modify only `tests/`.
- [x] `static-quality`: add PHPStan/PHPCS configuration and Composer scripts. Modify only `composer.json`, config files and docs.
- [x] `security-review`: read-only review of authentication, CORS, sessions, file and SQL tools. Produce `docs/security-review.md`; do not modify PHP source.

### P2 — sequential after P1

- [x] `crm-integration-tests`: add Bitrix-independent contract tests for CRM tool schemas and permission guarantees.
- [x] `mcp-inspector-config`: add example configurations and a smoke-test script for stdio MCP.
- [x] `rest-adapter-design`: design, but do not implement, a separate Bitrix24 Cloud REST adapter.

### P3 — requires main-agent review

- [x] `crm-write-dry-run`: design confirmation/dry-run API for write operations.
- [x] `audit-events`: design structured audit events with sensitive-data masking.
- [x] `cloud-rest-adapter`: implemented safe base adapter for webhook/OAuth token calls; OAuth authorization-code flow and persistent token storage remain deferred.

## Definition of done

- No credentials in repository.
- PHPUnit and PHP lint pass.
- Changes are documented.
- Production code changes have a focused test or an explicit reason why integration testing requires Bitrix24.
