#!/bin/sh
#
# Warns when the local database is behind the migration chain. Read-only: it asks
# doctrine:migrations:up-to-date, which runs a SELECT against doctrine_migration_versions and
# nothing else. It never runs migrate, never touches schema or data, and always exits 0 so it
# cannot block a pull or a checkout.
#
# Deliberately a warning rather than an automatic migrate. A migration is a write to the
# database, and a hook that applies whatever just arrived would run a destructive or wrong
# migration before anyone had looked at it. Branch switching makes it worse: migrations do not
# roll back, so checking out an older branch would leave old code running against a newer
# schema — quietly, which is the failure this exists to remove. The problem was never that
# migrations do not run by themselves; it is not finding out that they need to.
#
# Shared by post-merge and post-checkout.

set -u

# Hooks can run from anywhere (IDEs, GUI clients); resolve the repo root rather than assume cwd.
root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
[ -n "$root" ] || exit 0
cd "$root" || exit 0

# Nothing to say if this checkout cannot run the console at all — a fresh clone before
# `composer install`, a PHP-less environment, a deploy user without the CLI binary.
[ -f bin/console ] || exit 0
command -v php >/dev/null 2>&1 || exit 0
[ -f vendor/autoload_runtime.php ] || exit 0

# Stay quiet on anything other than a clean "you are behind". An unreachable database, a
# missing .env.local, a half-installed vendor tree — none of that is this hook's business, and
# a stack trace on every branch switch would train everyone to ignore the output.
output=$(php bin/console doctrine:migrations:up-to-date --no-interaction 2>/dev/null)
status=$?

case "$status" in
    0)
        # Up to date.
        exit 0
        ;;
    1)
        # Behind. This is the one case worth interrupting for.
        ;;
    *)
        # Could not tell (console error, no DB, misconfiguration). Say nothing.
        exit 0
        ;;
esac

# Only reached when migrations are genuinely pending.
printf '\n'
printf '  \033[33m┌─────────────────────────────────────────────────────────────┐\033[0m\n'
printf '  \033[33m│\033[0m  \033[1mDatabase is behind the migration chain\033[0m                     \033[33m│\033[0m\n'
printf '  \033[33m└─────────────────────────────────────────────────────────────┘\033[0m\n'
printf '\n'
printf '  %s\n' "$output" 2>/dev/null
printf '\n'
printf '  Run:  \033[1mphp bin/console doctrine:migrations:migrate\033[0m\n'
printf '\n'
printf '  Nothing has been applied — this is a warning only.\n'
printf '\n'

exit 0
