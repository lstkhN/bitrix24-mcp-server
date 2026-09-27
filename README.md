# Bitrix24 MCP Server

**Read-only Model Context Protocol server for Bitrix24 Box.** Connect Claude Code, Cursor, and
other MCP-compatible AI assistants to a Bitrix24 On-Premise ("Box") instance
for controlled inspection of its CRM, code, database schema, and project
structure. It speaks JSON-RPC 2.0 over SSH stdio or authenticated HTTP.

**Public name:** Bitrix24 MCP Server. The Composer package is `bitrix24/mcp`. For
installed Bitrix24 instances, the module directory and ID are `bitrix24.mcp`;
the PHP namespace is `Bitrix24\Mcp`.

> **Production scope:** Bitrix24 Box with D7, running on the same server as
> Bitrix24. Bitrix24 Cloud is not a drop-in MCP deployment; see
> [Cloud vs Box](#cloud-vs-box).

## Features

- **Read-only CRM tools** — list and inspect leads, deals, contacts, companies,
  activities, and timeline entries without modifying any data.
- **Code intelligence** — search project files, inspect PHP classes via
  reflection, list components and event handlers.
- **Database access** — schema inspection and SELECT queries without explicit
  DML, with row/time limits (not a SQL sandbox).
- **Business process reader** — view bizproc templates, instances, and tasks.
- **MCP resources** — project structure, D7 documentation, coding standards.
- **Two transports** — stdio over SSH (recommended for development) and
  Streamable HTTP with Bearer authentication.

## Cloud vs Box

| Capability | Box (On-Premise) | Cloud |
|---|---|---|
| Supported MCP deployment | ✅ Yes — module runs beside Bitrix24 | ❌ No — no Cloud MCP endpoint is provided |
| Full toolset (files, DB, reflection) | ✅ Yes — direct server access | ❌ No shell or database access |
| CRM tools exposed by this MCP server | ✅ Read-only D7 tools | ❌ Not exposed as Cloud MCP tools |
| REST adapter | N/A | ⚠️ Library foundation; inbound-webhook calls can be used separately |
| OAuth authorization-code callback/exchange/refresh | N/A | ❌ Not integrated or deployable yet |
| OAuth connection/token persistence | N/A | ⚠️ Storage/security building blocks exist, but no end-to-end OAuth lifecycle |

**Bottom line:** install this module for a Box instance. Cloud code is
foundation work, not a supported Cloud CRM MCP integration. For the current
Cloud REST design and limits, see [`docs/rest-adapter.md`](docs/rest-adapter.md).

## Requirements

- PHP ≥ 8.1
- Bitrix24 with D7 and ability to install local modules
- For HTTP: HTTPS domain with ability to add a route/endpoint
- For stdio: SSH access to the Bitrix24 server (recommended for development)

## Install and use

1. Place the module directory as `local/modules/bitrix24.mcp/` in a **Box** installation.
2. Install Composer dependencies:

   ```bash
   cd /path/to/bitrix/local/modules/bitrix24.mcp
   composer install --no-dev --prefer-dist --optimize-autoloader
   ```

3. In Bitrix24 admin, go to **Marketplace → Installed Solutions**
   (`/bitrix/admin/partner_modules.php`), find **Bitrix24 MCP Server**
   and click "Install".
4. Installation generates a random Bearer token and creates `/local/logs`.
   Keep the token out of Git, chat messages, and shell history.

Choose one transport below, add its client configuration, then verify the
connection with `tools/list`. For development, SSH stdio is the recommended
choice because it does not publish an HTTP endpoint.

To get the current HTTP token, run `cli/generate-config.php` from the Bitrix
environment. The script prints the token, so run it only in a secure console.

## Transports

### stdio via SSH

`cli/server.php` runs MCP over stdin/stdout. The client connects via SSH, so
no HTTP port needs to be exposed:

```json
{
  "mcpServers": {
    "bitrix24-mcp": {
      "command": "ssh",
      "args": [
        "bitrix@your-server.example",
        "php /path/to/bitrix/local/modules/bitrix24.mcp/cli/server.php"
      ]
    }
  }
}
```

### Streamable HTTP

Two options:

1. Copy `public/mcp-endpoint.php` to `/local/mcp/index.php` and use
   `https://example.com/local/mcp/`. Supports POST JSON-RPC, GET SSE keep-alive,
   DELETE session termination; all methods except OPTIONS require
   `Authorization: Bearer <token>`.

2. Connect `routes/mcp.php` to your Bitrix route config and use
   `POST https://example.com/api/mcp/`. POST only; GET and DELETE return 405.

```bash
curl -sS https://example.com/local/mcp/ \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

## Client Configuration

### Claude Code

Ready-to-copy stdio example: [`examples/claude-code.mcp.json`](examples/claude-code.mcp.json).

For HTTP:

```json
{
  "mcpServers": {
    "bitrix24-mcp": {
      "url": "https://example.com/local/mcp/",
      "headers": {
        "Authorization": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

### Cursor

Ready-to-copy stdio example: [`examples/cursor.mcp.json`](examples/cursor.mcp.json).

## Tools

Full JSON Schema returned by `tools/list`. Overview:

| Tool | Description | Limitations |
|---|---|---|
| `database_schema` | MySQL table schema from `INFORMATION_SCHEMA` | Schema read only |
| `crm_schema` | Fields, custom fields, statuses for CRM entities | Read only |
| `crm_entity_list` | List leads, deals, contacts, companies | **Read only**, max 100 per call |
| `crm_entity_get` | Single CRM entity by ID | **Read only** |
| `crm_activity_list` | CRM activities by entity or filter | **Read only** |
| `crm_timeline_list` | CRM timeline entries | **Read only** |
| `modules_list` | Installed modules, versions, paths | Read only |
| `code_search` | Text/regex search in project files | Read only |
| `class_inspector` | PHP reflection analysis | Read only |
| `components_list` | Bitrix components, templates, parameters | Read only |
| `event_handlers` | Registered event handlers | Read only |
| `file_reader` | Read project files (allowlisted dirs, line ranges) | Read only |
| `sql_query` | Read-oriented SELECT queries | **No explicit DML**, 500 rows / 30s limit |
| `bizproc_reader` | Bizproc templates, instances, tasks, history | Read only; `/api/mcp/` only |

> **No write operations exist.** CRM tools are strictly read-only. Write
> workflow design is documented in `docs/crm-write-dry-run.md` but has not
> been implemented.

## Security and limits

This is an inspection tool for trusted operators, not a tenant boundary or a
safe way to expose Bitrix24 to the internet. Restrict network access, use a
least-privilege Bitrix/PHP identity, and treat every MCP client with the same
trust as a user who can request the listed data.

- HTTP endpoint is protected by Bearer token from module settings.
- `hash_equals` is used for token comparison (timing-safe).
- `file_reader` is restricted to `/local/`, `/bitrix/modules/`,
  `/bitrix/components/`, `/bitrix/templates/`; `..` traversal is blocked;
  `dbconn.php` and `.env` files are explicitly forbidden.
- `sql_query` accepts read-oriented `SELECT` statements; it is not a complete SQL sandbox and must not be exposed to untrusted users.
- CRM tools respect the permissions of the Bitrix24 identity used by the PHP process; an administrator-level process can read administrator-visible CRM data.

**Known security considerations** (see [`docs/security-review.md`](docs/security-review.md)):

- CORS uses `Access-Control-Allow-Origin: *` — not ideal for privileged API
  (SEC-01).
- SQL tool uses blacklist filtering; some side-effect queries may pass (SEC-02).
- File reader loads entire file into memory — no size limit (SEC-03).
- MCP session IDs are not server-tracked (SEC-05).

## Testing

`composer test` runs hermetic unit tests only. Live Box and Cloud-webhook
checks are deliberately excluded from normal CI and require an explicit local
opt-in:

```bash
BITRIX24_RUN_INTEGRATION=1 composer test:integration
```

Use disposable or non-production environments and least-privilege secrets.
The integration suite makes no write calls; the Cloud check is an inbound
webhook `profile` read, not OAuth or a Cloud MCP CRM test. See
[docs/integration-tests.md](docs/integration-tests.md).

## Diagnostics

- `401` — missing Bearer header; `403` — invalid token; `405` — wrong HTTP method.
- Ensure the module is installed, `vendor/autoload.php` is available, PHP ≥ 8.1,
  and requests have `Content-Type: application/json`.

## Project Structure

```
bitrix24-mcp-server/
├── cli/              # CLI scripts (server, config, connection test)
├── docs/             # Design docs, security review, audit events
├── examples/         # Client config examples (Cursor, Claude)
├── lib/              # PHP source
│   ├── Cloud/        # Bitrix24 Cloud REST adapter
│   ├── Controller/   # API controller
│   ├── Resources/    # MCP resources (structure, D7 docs, standards)
│   ├── Tools/        # MCP tools (CRM, DB, code, file, SQL)
│   └── Transport/    # stdio and HTTP transports
├── public/           # HTTP endpoints
├── routes/           # Bitrix route integration
├── tests/            # PHPUnit tests
├── CHANGELOG.md
├── CONTRIBUTING.md
├── LICENSE           # MIT
└── .env.example
```

### Local quality checks

```bash
composer test         # Hermetic PHPUnit unit tests
composer phpstan      # Static analysis
composer phpcs        # Code style (PSR-12)
composer quality      # Static analysis and code style
```

## License

This project is released under the [MIT License](LICENSE). Copyright © 2025 Bitrix24 Team.

You may use, modify, redistribute, and sublicense the software under the MIT
terms. Keep the copyright and license notice in redistributed copies. Bitrix24,
Claude, and Cursor remain trademarks/products of their respective owners and
are not affiliated with this project.
