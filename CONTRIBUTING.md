# Contributing to Bitrix24 MCP Server

Thank you for considering contributing! This document covers how to set up a
development environment, submit changes, and what to expect during review.

## Ground Rules

- **No credentials in commits.** Never commit Bearer tokens, OAuth secrets,
  webhook codes, database passwords or API keys. Use `.env` (see
  `.env.example`) and add real `.env` files to `.gitignore`.
- **No production secrets in tests.** Test fixtures must use fake values.
- **Read-only by default.** CRM tools currently support read operations only.
  Write operations are not yet implemented and will require a separate
  confirmation workflow (see `docs/crm-write-dry-run.md`).
- **PHP code must not be modified by this process.** Portfolio preparation
  touches only documentation and configuration files.

## Development Setup

1. Clone the repository and enter the module directory:

   ```bash
   git clone <repo-url>
   cd bitrix24.mcp
   ```

2. Install development dependencies:

   ```bash
   composer install
   ```

3. Copy the environment template and fill in values for your local Bitrix24
   installation:

   ```bash
   cp .env.example .env
   ```

4. Run quality checks:

   ```bash
   composer quality    # PHPStan + PHPCS
   composer test       # PHPUnit
   ```

## Code Style

- PSR-12 (enforced by PHPCS via `phpcs.xml.dist`).
- PHPStan level configured in `phpstan.neon.dist`.

## Submitting Changes

1. Fork the repository and create a feature branch from `main`.
2. Make your changes in small, focused commits.
3. Ensure `composer quality` and `composer test` pass.
4. Open a pull request with a clear description of **what** changed and
   **why**.

## Reporting Issues

Open a GitHub issue with:

- Steps to reproduce.
- Expected vs. actual behavior.
- PHP version and Bitrix24 version.

Security issues should be reported privately (see `docs/security.md`).

## Project Structure

```
bitrix24.mcp/
├── cli/              # CLI scripts (server, config generation)
├── docs/             # Design docs, security review, audit events
├── examples/         # Client configuration examples (Cursor, Claude)
├── lib/              # PHP source (Tools, Transport, Cloud, Resources)
├── public/           # HTTP endpoints
├── routes/           # Bitrix route integration
├── tests/            # PHPUnit tests
└── composer.json     # Dependencies and scripts
```

## License

By contributing, you agree that your contributions will be licensed under the
MIT License.
