#!/usr/bin/env bash
#
# Put an uploaded release live. Run on the SERVER, by the deploy job:
#
#     ssh user@host 'bash -s' -- /var/www/bcs <sha> < deploy/release.sh
#
# and runnable by hand with the same two arguments when something has gone
# wrong and you want to redo the last step without a whole pipeline.
#
# THE ORDER IS THE POINT OF THIS FILE. Migrations run from the NEW release
# while `current` still points at the old one, so a migration that fails leaves
# the symlink where it was and the old code intact. The swap is the last thing
# that happens before the site comes back.
set -euo pipefail

ROOT="${1:?usage: release.sh <deploy-root> <sha>}"
SHA="${2:?usage: release.sh <deploy-root> <sha>}"

RELEASE="$ROOT/releases/$SHA"
SHARED="$ROOT/shared"
CURRENT="$ROOT/current"
KEEP=5

[ -d "$RELEASE" ] || { echo "No such release: $RELEASE"; exit 1; }

# ---------------------------------------------------------------- shared state

# NEVER WRITTEN, ONLY LINKED, and the deploy stops if it is missing rather than
# helpfully creating one. APP_KEY lives in here, and every association's gateway
# credentials are encrypted with it - a fresh .env with a fresh key does not
# lose the credentials, it makes them permanently unreadable while leaving rows
# that look fine. There is no recovery from that except retyping every
# association's merchant secrets, if anyone still has them.
if [ ! -f "$SHARED/.env" ]; then
    echo "FATAL: $SHARED/.env does not exist."
    echo "Create it once, by hand, before the first deploy. Do not let CI write it:"
    echo "it holds APP_KEY, and every tenant's gateway credentials are encrypted with it."
    exit 1
fi

ln -sfn "$SHARED/.env" "$RELEASE/.env"

# Storage is shared for the same reason it is not in git: members' uploaded
# documents live under storage/tenant*/. A release with its own empty storage
# would 404 every document the association has ever uploaded.
rm -rf "$RELEASE/storage"
ln -sfn "$SHARED/storage" "$RELEASE/storage"

cd "$RELEASE"

# --------------------------------------------------------------------- migrate

# Maintenance mode goes on BEFORE the schema moves, because a request served
# mid-migration reads half a schema with the other half's code.
if [ -L "$CURRENT" ]; then
    php artisan down --retry=15 || true
fi

# Left DOWN on failure, on purpose. A half-migrated database served by old code
# is worse than a maintenance page, and this is a decision for a person: read
# the error, fix it or roll the migration back, then `php artisan up`.
trap 'echo; echo "MIGRATION FAILED. The site is still in maintenance mode and current/ still points at the previous release."; echo "Fix the migration, then: cd $CURRENT && php artisan up"; exit 1' ERR

# Central first: it holds the tenants table that the next command reads.
php artisan migrate --force

# Then every association, each in its own database (ADR-0001). This is the one
# that grows with the customer list, and the one that fails per tenant rather
# than all at once.
php artisan tenants:migrate --force

trap - ERR

# ----------------------------------------------------------------------- cache

# After the migrations, before the swap: config, routes, views and events all
# get compiled into the release that is about to become current.
php artisan optimize

# ------------------------------------------------------------------ go live

ln -sfn "$RELEASE" "$CURRENT"

# php-fpm caches the resolved path of the old symlink in opcache, so without
# this it keeps serving the PREVIOUS release out of memory - a deploy that
# reports success and changes nothing. Loud failure, therefore, rather than a
# warning: a silently stale deploy is the thing this is here to prevent.
#
# The unit is DISCOVERED, not hard-coded to a PHP version. `php8.3-fpm` was
# right on the stack this was written against and wrong on Ubuntu 26.04, whose
# archive has no 8.3 at all. Override with FPM_SERVICE if the name is unusual.
if command -v systemctl >/dev/null 2>&1; then
    FPM_SERVICE="${FPM_SERVICE:-$(systemctl list-units --type=service --all --no-legend 'php*-fpm.service' 2>/dev/null | awk '{print $1}' | head -1)}"

    if [ -z "$FPM_SERVICE" ]; then
        echo "FATAL: no php*-fpm service found, so the new code would not be served."
        echo "Set FPM_SERVICE to the unit name, or reload PHP yourself and rerun."
        exit 1
    fi

    # Root over ssh needs no sudo, and sudo may not be configured for it.
    if [ "$(id -u)" -eq 0 ]; then
        systemctl reload "$FPM_SERVICE"
    else
        sudo systemctl reload "$FPM_SERVICE"
    fi
fi

# Workers hold the old code in memory for the length of their process. This
# asks them to finish the job in hand and exit; systemd starts them again.
php artisan queue:restart

php artisan up

# ---------------------------------------------------------------- housekeeping

# Keep a few to roll back to - `ln -sfn $ROOT/releases/<sha> $ROOT/current`
# and reload php-fpm is the whole rollback, as long as no migration ran.
cd "$ROOT/releases"
ls -1t | tail -n "+$((KEEP + 1))" | xargs -r rm -rf

echo "Deployed $SHA"
