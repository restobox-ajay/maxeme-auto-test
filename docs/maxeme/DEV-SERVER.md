# Dev server (set up 2026-11-23)

https://maxemenew.dev.rp017.webhelplogin.com — the Maxeme app at `/` (opens `/admin`), the mockup at `/mockup/`.

## Deploy the app

```bash
git push dev main
```

`dev` = `ssh://maxemend@maxemenew.dev.rp017.webhelplogin.com:2200/home/maxemend/app-root`, a plain repo on the
server with `receive.denyCurrentBranch=updateInstead`: the push updates the files, then the `post-receive` hook
(`~/app-root/.git/hooks/post-receive`, not in git) finishes the deploy and prints what it did:

- `composer install --no-dev` when `composer.json` / `composer.lock` changed (`~/composer.phar`)
- `doctrine:migrations:migrate`
- `cache:clear`, `assets:install public`

Its log: `~/app-root/var/log/deploy.log`. A push is refused if someone edited tracked files on the server: commit
the change here and push instead.

## Server layout (`/home/maxemend`)

| Path | What |
|---|---|
| `app-root/` | the app (branch `main`); `711`, so the web server passes through but cannot list |
| `app-root/.env.local` | server settings, not in git (`600`): `APP_ENV=prod`, its own `APP_SECRET`, `DEFAULT_URI` and `ADMIN_HOST` = the dev host, `MAILER_DSN="null://null"`, `IMPORT_PHP_BINARY` |
| `app-root/var/data_prod.db` | SQLite (`600`), a copy of the local database of 2026-11-23 (real customer data from the legacy import, plus QA records) |
| `app-root/public/` | web root; `~/public_html` is a link to it |
| `app-root/public/mockup` | link to `mockup-root/public/mockup` |
| `app-root/public/pull-*.php` | link to the mockup's "Proceed" pull page |
| `app-root/public/.user.ini`, `php.ini`, `.well-known/` | cPanel's, copied from the mockup's web root (ignored by git) |
| `mockup-root/` | the mockup's own checkout (branch `mockup`, origin GitHub `axcelmediacorp/maxemeauto-symfony`); updated by its pull page, or `git push deploy mockup` from here |

PHP: `/opt/cpanel/ea-php82/root/usr/bin/php` (8.2, the site's default).

## Email

None leaves this server: `MAILER_DSN="null://null"` (the only mail transport; the database holds display
addresses only), no `messenger:consume` worker, `GHL_API_TOKEN` blank. Keep it that way while the database holds
real customer addresses.

## Not set up

- The old hourly cron runs `~/app-root/scripts/pull.sh`, which the app does not have (it used to be the mockup's
  and failed every hour even then, as it ran outside the repo). Point it at `cd ~/mockup-root && scripts/pull.sh`
  if the mockup should pull from GitHub on its own, or remove it.
- No cron for the app (message queue, reminders): email is off anyway.
- The mockup's old cache headers (`no-store` for HTML) were in the mockup's web-root `.htaccess`, which is no longer
  the web root.

## Undo

Point the site back at the mockup only: `ln -sfn /home/maxemend/mockup-root/public ~/public_html`.
