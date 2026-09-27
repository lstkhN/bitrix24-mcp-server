# Changelog

All notable changes to Bitrix24 MCP Server will be documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Breaking Changes
- Renamed the PHP namespace to `Bitrix24\Mcp`, the Composer package to
  `bitrix24/mcp`, and the Bitrix module ID to `bitrix24.mcp`.
- Renamed the public product to `Bitrix24 MCP Server`.

### Added
- `CONTRIBUTING.md` — contributor guidelines and development setup.
- `CHANGELOG.md` — this file.
- `.env.example` — documented module settings template.
- `LICENSE` — MIT license file.

### Changed
- Public name updated to `Bitrix24 MCP Server`.
- `composer.lock` is now included in releases.
- Removed legacy `public/api.php` and `public/mcp-sse.php` endpoints.
- `README.md` — rewritten for GitHub portfolio: honest Box vs Cloud description,
  explicit OAuth limitation notes, clearer tool capability table.

---

## [0.x] — 2025

### Added
- MCP server with JSON-RPC 2.0 protocol over stdio and Streamable HTTP.
- **Box (On-Premise) support** — primary target, requires Bitrix24 with D7.
- **Cloud REST adapter** — webhook-based calls implemented; OAuth
  authorization-code flow and persistent token storage are deferred
  (see `docs/rest-adapter.md`).
- Read-only CRM tools:
  - `crm_entity_list`, `crm_entity_get` — leads, deals, contacts, companies.
  - `crm_activity_list` — CRM activities by entity or filter.
  - `crm_timeline_list` — CRM timeline entries.
  - `crm_schema` — fields, custom fields, statuses for CRM entities.
- Development tools:
  - `database_schema` — MySQL table schema via INFORMATION_SCHEMA.
  - `code_search` — text/regex search in project files.
  - `class_inspector` — PHP reflection analysis.
  - `components_list` — Bitrix components, templates, parameters.
  - `event_handlers` — registered Bitrix event handlers.
  - `file_reader` — read project files with allowlist and line range.
  - `sql_query` — SELECT-only queries, 500 rows / 30s limits.
  - `modules_list` — installed modules, versions, paths.
- `bizproc_reader` — business process templates, instances, tasks, history
  (available via `/api/mcp/`, not in stdio or `public/mcp-endpoint.php`).
- MCP resources: project structure, D7 documentation, coding standards.
- Transports: stdio via SSH, Streamable HTTP with Bearer auth.
- Client configs: Cursor (`examples/cursor.mcp.json`),
  Claude Code (`examples/claude-code.mcp.json`).
- Security: Bearer token auth, `hash_equals` comparison, file allowlist,
  SQL SELECT-only restriction.
- Tests: PHPUnit unit tests for tools, transport, Cloud adapter, protocol.
- Static analysis: PHPStan + PHPCS (PSR-12).
- Design docs: security review, CRM write dry-run, audit events, REST adapter.

### Known Limitations
- **Box only for full functionality** — Cloud support is limited to webhook-based
  REST calls via the Cloud adapter; MCP tools (file system, database, class
  inspector) require direct server access.
- **No CRM write operations** — all CRM tools are read-only; write workflow
  design exists (`docs/crm-write-dry-run.md`) but is not implemented.
- **OAuth deferred** — the Cloud adapter supports webhook auth; OAuth
  authorization-code flow and persistent token storage remain a separate phase.
- **CORS is permissive** — `Access-Control-Allow-Origin: *` is used; see
  `docs/security-review.md` SEC-01 for details.
- **SQL tool has known weaknesses** — blacklist-based filtering may allow
  `FOR UPDATE`, `GET_LOCK`, `SLOCK`; see `docs/security-review.md` SEC-02.
- **File reader reads entire file into memory** — no size limit enforced;
  see `docs/security-review.md` SEC-03.
