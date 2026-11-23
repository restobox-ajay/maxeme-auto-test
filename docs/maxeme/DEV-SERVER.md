# Dev server (set up 2026-11-23)

https://maxemenew.dev.rp017.webhelplogin.com — the Maxeme app at `/` (opens `/admin`), the mockup at `/mockup/`.

## Deploy the app

```bash
git push dev main
```

`dev` = `ssh://maxemend@maxemenew.dev.rp017.webhelplogin.com:2200/home/maxemend/app-root`, a plain repo on the
server with `receive.denyCurrentBranch=updateInstead`: the push updates the files, then the `post-receive` hook
(`~/app-root/.git/hooks/post-receive`, not in git; the server repo has `core.hooksPath` set to its own `.git/hooks`, as the composer scripts would otherwise point it at `.githooks`) finishes the deploy and prints what it did:

- `composer install --no-dev` when `composer.json` / `composer.lock` changed (`~/composer.phar`)
- `doctrine:migrations:migrate`
- `cache:clear`, `assets:install public`

Its log: `~/app-root/var/log/deploy.log`. A push is refused if someone edited tracked files on the server: commit
the change here and push instead.

## Server layout (`/home/maxemend`)

| Path | What |
|---|---|
| `app-root/` | the app (branch `main`); `711`, so the web server passes through but cannot list |
| `app-root/.env.local` | server settings, not in git (`600`): `APP_ENV=prod`, its own `APP_SECRET`, `DEFAULT_URI` and `ADMIN_HOST` = the dev host, `MAILER_DSN` / `MAILER_FROM` (see Email), `IMPORT_PHP_BINARY` |
| `app-root/var/data_prod.db` | SQLite (`600`). Copied from local on 2026-11-23, then cleaned the same day: no clients, vehicles, appointments, repair orders, invoices, payments, reminders, stock movements or logs; the settings, tax classes, payment types, email templates, the real service catalogue (341) and parts (55, stock 0) kept; the test catalogue (QA / test services, all labour, government fees and service categories, QA parts, category and vendor) and the `@example.invalid` accounts removed; then all staff accounts but `ajay@restobox.com` removed, and `ken@restobox.com` added (both Super Admin + Tech Support; Ken sets his password with "Forgot your password?"). `var/data_prod.db.bak-20261122225344` is the uncleaned copy, `var/data_prod.db.bak-before-staff-removal-*` the one with the 11 staff |
| `app-root/public/` | web root; `~/public_html` is a link to it |
| `app-root/public/mockup` | link to `mockup-root/public/mockup` |
| `app-root/public/pull-*.php` | link to the mockup's "Proceed" pull page |
| `app-root/public/.user.ini`, `php.ini`, `.well-known/` | cPanel's, copied from the mockup's web root (ignored by git) |
| `mockup-root/` | the mockup's own checkout (branch `mockup`, origin GitHub `axcelmediacorp/maxemeauto-symfony`); updated by its pull page, or `git push deploy mockup` from here |

PHP: `/opt/cpanel/ea-php82/root/usr/bin/php` (8.2, the site's default).

## Backups

The server's SQLite is 3.26, which has no `VACUUM INTO`: back up with `PRAGMA wal_checkpoint(TRUNCATE)` then `cp -p`.

## Email (switched on 2026-11-23)

The app sends through the server's cPanel mailbox **no-reply@maxemenew.dev.rp017.webhelplogin.com**:
`MAILER_DSN="smtps://no-reply%40maxemenew.dev.rp017.webhelplogin.com:<password>@mail.maxemenew.dev.rp017.webhelplogin.com:465"`
(the `@` in the user written once as `%40`, not `%%40`), `MAILER_FROM=no-reply@maxemenew.dev.rp017.webhelplogin.com`.
The password was generated on the server and is kept only in `~/.no-reply-mailbox-pass` (`600`) and `.env.local`;
after changing it in cPanel (Email Accounts), update both and run `cache:clear`. Earlier settings: `.env.local.bak-*`.

Checked: `bin/console mailer:test` to the mailbox itself arrived. Emails go through the queue (the cron worker
sends them within a minute). The database holds no clients and two staff logins (ajay@ and ken@restobox.com); staff added later get real
invitations and password resets. To switch email off again: `MAILER_DSN="null://null"`, then
`cache:clear`.

## Cron (installed 2026-11-23; file `~/maxeme.crontab`, earlier crontab kept as `~/crontab.bak-*`)

Server time is Mountain (MST/MDT); the shop runs on Vancouver time, one hour behind.

| When (server time) | Job | Log (`~/app-root/var/log/`) |
|---|---|---|
| every minute | `messenger:consume async --time-limit=55 --limit=100` (emails, GHL calls) | `messenger.log` |
| every minute | `import:process` (queued product imports; exits at once when none) | `import.log` |
| :15 every hour | `app:inventory-recalc` (stock buckets drift) | `cron.log` |
| 06:00 (05:00 shop) | `app:maxeme:generate-reminders` | `cron.log` |
| 10:00 (09:00 shop) | `app:maxeme:send-service-reminders` (marked Sent; nothing leaves the server) | `cron.log` |
| hourly | mockup: `cd ~/mockup-root && scripts/pull.sh` (pulls the `mockup` branch from GitHub) | `~/pull.log` |

Change: edit `~/maxeme.crontab`, then `crontab ~/maxeme.crontab`.

## Not set up

- The mockup's old cache headers (`no-store` for HTML) were in the mockup's web-root `.htaccess`, which is no longer
  the web root.

## Undo

Point the site back at the mockup only: `ln -sfn /home/maxemend/mockup-root/public ~/public_html`.
