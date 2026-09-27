# Static quality

Development dependencies are installed with:

```bash
composer install
```

Run the standard checks individually or together:

```bash
composer phpstan
composer phpcs
composer quality
```

Unit tests run with `composer test`. Live Box/Cloud checks are separately
opt-in and are never part of the normal CI workflow; see
[Integration tests](integration-tests.md).

PHPStan analyses the project library using `phpstan.neon.dist`. PHPCS uses the
PSR-12 ruleset from `phpcs.xml.dist`.

## Bitrix-aware PHPStan

`phpstan.bitrix.neon.dist` is an opt-in profile for checking calls to the
Bitrix Main and CRM APIs against an actual licensed Bitrix installation. It
keeps the same PHPStan level and type-certainty settings as the standard
profile; it contains no baselines, ignored errors, or rule suppressions.

Set `BITRIX_ROOT` to the document root containing `bitrix/modules` and run:

```bash
BITRIX_ROOT=/path/to/bitrix-site composer phpstan:bitrix
```

The profile uses PHPStan `scanDirectories` for the relevant `main` and `crm`
module source trees, so it does not bootstrap or execute the CMS. This command
is deliberately not part of the public GitHub Actions job: the Bitrix runtime
is proprietary and is not available to hosted CI. Run it in a licensed local
or self-hosted CI checkout where `BITRIX_ROOT` is available.

The small `tests/Stubs/BitrixStubs.php` file remains only for unit tests. It is
not an API-complete replacement for Bitrix and is intentionally not used by
the Bitrix-aware profile; incomplete stubs would hide API-signature errors.
