# CustomHeaderFooterBundle

**Type:** Injection Point · **Name:** Custom Header & Footer HTML · **Edit route:** `/admin/bundles/custom-header-footer`

## What it does

Lets a superadmin paste raw HTML/CSS/JS to inject into every page on the customer side only (never the admin side):

- **Custom Header HTML** — rendered via the `customer_head_top` injection point, near the end of `<head>` in `templates/customer/_main/layout.html.twig`.
- **Custom Footer HTML** — rendered via the `customer_body_end` injection point, right before `</body>`.

Both points are new call sites added to `templates/base.html.twig` (as the empty `head_extra` / `body_end` blocks, so the admin-side layout is unaffected) and `templates/customer/_main/layout.html.twig` (which fills them with `injection_point('customer_head_top')` / `injection_point('customer_body_end')`). See [docs/bundles/injection-points.md](../../docs/bundles/injection-points.md) for the full injection point mechanism.

## How it's configured

Admin screen at `/admin/bundles/custom-header-footer` (`CustomHeaderFooterController`) — two textareas, saved via `CustomHeaderFooterStore` into two `AppSetting` rows (`custom_header_html`, `custom_footer_html`). No new entity or migration.

## External dependencies

None.

## Tests

This repo has no project-wide test runner configured yet, so this bundle carries its own
self-contained PHPUnit and Codeception setups (no other bundle needs to add either — these
configs exist only because this one already does).

Unit tests (`tests/Unit/`) — pure PHPUnit, no kernel boot, no database. `CustomHeaderFooterStore`
is exercised against an in-memory fake of the two Doctrine calls it makes, backed by a real
`Symfony\Component\Cache\Adapter\ArrayAdapter` for the `AppSettings` cache layer it wraps:

```
vendor/bin/phpunit -c modules/CustomHeaderFooterBundle/phpunit.xml.dist
```

Functional tests (`tests/functional/`) — Codeception with the Symfony module, boots the real
kernel/container/Twig and hits real routes (customer homepage, admin login, the admin save
form) against a throwaway SQLite database at `var/data_test.db` (configured via
the repo's `.env.test`, schema created on the fly via Doctrine's `SchemaTool` — never touches
the dev database at `var/data_dev.db`):

```
vendor/bin/codecept run functional -c modules/CustomHeaderFooterBundle/codeception.yml
```

`tests/_support/_generated/FunctionalTesterActions.php` is committed (Codeception's generated
actor trait) so the suite runs with no extra setup step. If `functional.suite.yml`'s enabled
modules ever change, regenerate it with
`vendor/bin/codecept build -c modules/CustomHeaderFooterBundle/codeception.yml`.

## See also

[docs/bundles/injection-points.md](../../docs/bundles/injection-points.md)
