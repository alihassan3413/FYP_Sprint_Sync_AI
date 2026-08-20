#!/usr/bin/env bash
#
# Drops every table, re-runs all migrations, and optionally re-seeds.
#
# This DESTROYS ALL DATA in the configured database. It is meant for
# development and staging environments. Production is refused unless you
# pass --force, and even then it asks you to type the database name.
#
# Usage:
#   ./scripts/refresh-database.sh                 # refresh + seed, with confirmation
#   ./scripts/refresh-database.sh --no-seed       # refresh only
#   ./scripts/refresh-database.sh --yes           # skip the confirmation prompt
#   ./scripts/refresh-database.sh --no-backup     # skip the pre-flight dump
#   ./scripts/refresh-database.sh --force         # allow running in production

set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

usage() {
    cat <<'USAGE'
Drops every table, re-runs all migrations, and optionally re-seeds.

This DESTROYS ALL DATA in the configured database. It is meant for
development and staging. Production is refused unless you pass --force,
and even then it asks you to type the database name.

Usage:
  ./scripts/refresh-database.sh                 refresh + seed, with confirmation
  ./scripts/refresh-database.sh --no-seed       refresh only, no seeders
  ./scripts/refresh-database.sh --yes           skip the confirmation prompt
  ./scripts/refresh-database.sh --no-backup     skip the pre-flight dump
  ./scripts/refresh-database.sh --force         allow running when APP_ENV=production
USAGE
}

SEED=1
ASSUME_YES=0
BACKUP=1
FORCE_PRODUCTION=0

for arg in "$@"; do
    case "$arg" in
        --no-seed)    SEED=0 ;;
        --yes|-y)     ASSUME_YES=1 ;;
        --no-backup)  BACKUP=0 ;;
        --force)      FORCE_PRODUCTION=1 ;;
        --help|-h)    usage; exit 0 ;;
        *)            echo "Unknown option: $arg (try --help)" >&2; exit 2 ;;
    esac
done

if [[ ! -f artisan ]]; then
    echo "Could not find artisan. Run this from the project root." >&2
    exit 1
fi

php_config() {
    php artisan tinker --execute "echo config('$1');" 2>/dev/null | tail -n 1 | tr -d '\r'
}

APP_ENV="$(php_config 'app.env')"
CONNECTION="$(php_config 'database.default')"
DATABASE="$(php_config "database.connections.${CONNECTION}.database")"
DB_HOST="$(php_config "database.connections.${CONNECTION}.host")"

echo "────────────────────────────────────────────────"
echo " Environment : ${APP_ENV}"
echo " Connection  : ${CONNECTION}"
echo " Database    : ${DATABASE}"
echo " Host        : ${DB_HOST:-n/a}"
echo " Seed after  : $([[ $SEED -eq 1 ]] && echo yes || echo no)"
echo "────────────────────────────────────────────────"
echo
echo "This will DROP EVERY TABLE and delete all data in the database above."
echo

if [[ "$APP_ENV" == "production" && $FORCE_PRODUCTION -ne 1 ]]; then
    echo "Refusing to run: APP_ENV is production." >&2
    echo "If you genuinely mean to wipe production, re-run with --force." >&2
    exit 1
fi

if [[ "$APP_ENV" == "production" ]]; then
    echo "!! PRODUCTION !! Type the database name (${DATABASE}) to continue:"
    read -r typed
    if [[ "$typed" != "$DATABASE" ]]; then
        echo "Database name did not match. Aborting." >&2
        exit 1
    fi
elif [[ $ASSUME_YES -ne 1 ]]; then
    read -r -p "Type 'refresh' to continue: " typed
    if [[ "$typed" != "refresh" ]]; then
        echo "Aborted." >&2
        exit 1
    fi
fi

if [[ $BACKUP -eq 1 ]]; then
    STAMP="$(date +%Y%m%d-%H%M%S)"
    mkdir -p storage/app/private/db-backups

    if [[ "$CONNECTION" == "sqlite" && -f "$DATABASE" ]]; then
        cp "$DATABASE" "storage/app/private/db-backups/${STAMP}.sqlite"
        echo "Backup written to storage/app/private/db-backups/${STAMP}.sqlite"
    elif [[ "$CONNECTION" == "mysql" || "$CONNECTION" == "mariadb" ]] && command -v mysqldump >/dev/null 2>&1; then
        DB_USER="$(php_config "database.connections.${CONNECTION}.username")"
        DB_PASS="$(php_config "database.connections.${CONNECTION}.password")"
        MYSQL_PWD="$DB_PASS" mysqldump --host="$DB_HOST" --user="$DB_USER" "$DATABASE" \
            > "storage/app/private/db-backups/${STAMP}.sql"
        echo "Backup written to storage/app/private/db-backups/${STAMP}.sql"
    elif [[ "$CONNECTION" == "pgsql" ]] && command -v pg_dump >/dev/null 2>&1; then
        DB_USER="$(php_config "database.connections.${CONNECTION}.username")"
        DB_PASS="$(php_config "database.connections.${CONNECTION}.password")"
        PGPASSWORD="$DB_PASS" pg_dump --host="$DB_HOST" --username="$DB_USER" "$DATABASE" \
            > "storage/app/private/db-backups/${STAMP}.sql"
        echo "Backup written to storage/app/private/db-backups/${STAMP}.sql"
    else
        echo "No backup tool available for '${CONNECTION}' — continuing without a backup."
    fi
fi

echo
php artisan down --render=errors::503 >/dev/null 2>&1 || true
trap 'php artisan up >/dev/null 2>&1 || true' EXIT

if [[ $SEED -eq 1 ]]; then
    php artisan migrate:fresh --seed --force
else
    php artisan migrate:fresh --force
fi

php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

php artisan queue:clear --force >/dev/null 2>&1 || true

echo
echo "Database refreshed."
echo "Restart your queue worker so it picks up the new schema:  php artisan queue:restart"
