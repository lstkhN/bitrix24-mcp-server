# Opt-in Bitrix integration tests

Live tests are intentionally separate from unit tests and are **not** run by
`composer test` or the standard GitHub Actions workflow. They require an
explicit local opt-in:

```bash
BITRIX24_RUN_INTEGRATION=1 composer test:integration
```

The suite has no write calls. The Box check only boots Bitrix and verifies that
the module loads. The Cloud check calls the read-only REST `profile` method via
an inbound webhook. It does not use OAuth, exchange tokens, or persist tokens.

## Credentials and configuration

Put real values only in your shell environment or an untracked root `.env`
file. PHPUnit does not load `.env` automatically, so source one deliberately
(e.g. `set -a; . ./.env; set +a`) before running the suite. Root `.env*` files
are ignored, except the committed `.env.example` template. Never pass secrets
on a command line that may be saved in shell history, and never add them to CI
variables for the normal workflow.

### Box

Set this to an absolute path in a disposable or non-production Bitrix Box
instance:

```bash
export BITRIX24_BOX_BOOTSTRAP=/var/www/bitrix/bitrix/modules/main/include/prolog_before.php
```

The bootstrap must load the installation containing the `bitrix24.mcp` module.
If it is not set, the Box test is skipped.

### Cloud

Create a least-privilege inbound webhook that may call `profile` only, then
set:

```bash
export BITRIX24_CLOUD_PORTAL_URL=https://your-portal.bitrix24.com
export BITRIX24_CLOUD_WEBHOOK_USER_ID=1
export BITRIX24_CLOUD_WEBHOOK_CODE='local-secret'
export BITRIX24_CLOUD_TENANT=optional-local-label
```

`BITRIX24_CLOUD_TENANT` is optional and used only as a diagnostic label. Missing
required Cloud settings skip the Cloud test. Prefer a dedicated test portal and
revoke the webhook after use.

## Running one target

PHPUnit filtering lets you run one test after the opt-in gate is set:

```bash
BITRIX24_RUN_INTEGRATION=1 BITRIX24_BOX_BOOTSTRAP=/path/to/prolog_before.php \
  vendor/bin/phpunit --configuration=phpunit.integration.xml.dist --filter BoxModuleLoadTest
```

Do not add `composer test:integration` to the ordinary CI workflow. A separate,
explicitly approved secret-enabled workflow may invoke it in a dedicated test
environment.
