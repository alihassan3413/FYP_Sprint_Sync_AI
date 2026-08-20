#!/usr/bin/env bash
#
# Sprint Sync AI — redeploy an already-provisioned server.
#
#   sudo bash /var/www/<domain>/deploy/deploy.sh
#
# Pulls the branch, rebuilds, migrates, recaches and restarts the workers.
# Run provision.sh first — this assumes the server is already set up.
#
# Must run as root, but every build step is executed as the app user so the
# tree never ends up root-owned (which would break PHP-FPM's writes to
# storage/ and the SQLite file).

set -Eeuo pipefail

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
APP_SLUG="${APP_SLUG:-sprintsync}"
APP_USER="${APP_USER:-${APP_SLUG}}"
BRANCH="${BRANCH:-main}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_VERSION="${NODE_VERSION:-22.11.0}"
PHP_BIN="/usr/bin/php${PHP_VERSION}"
NODE_PREFIX="/opt/node-${NODE_VERSION}"

log() { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()  { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
die() { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

as_app() {
  sudo -u "$APP_USER" -H \
    env PATH="${NODE_PREFIX}/bin:/usr/local/bin:/usr/bin:/bin" \
        COMPOSER_HOME="/home/${APP_USER}/.composer" \
        NODE_OPTIONS=--max-old-space-size=1024 \
    "$@"
}

[ "$(id -u)" -eq 0 ] || die "run as root: sudo bash ${BASH_SOURCE[0]}"
[ -f "${APP_DIR}/artisan" ] || die "${APP_DIR} does not look like the Laravel app."
[ -x "$PHP_BIN" ] || die "${PHP_BIN} not found — run provision.sh first."
id "$APP_USER" >/dev/null 2>&1 || die "user ${APP_USER} does not exist — run provision.sh first."

cd "$APP_DIR"

# Always leave maintenance mode, even if a step below blows up.
trap 'as_app "$PHP_BIN" "${APP_DIR}/artisan" up >/dev/null 2>&1 || true' EXIT

log "Backing up the database"
as_app mkdir -p "${APP_DIR}/storage/backups"
as_app cp "${APP_DIR}/database/database.sqlite" "${APP_DIR}/storage/backups/database-$(date +%F-%H%M%S).sqlite"
ls -1t "${APP_DIR}"/storage/backups/database-*.sqlite 2>/dev/null | tail -n +11 | xargs -r rm --
ok "backup written (keeping the 10 most recent)"

log "Pulling ${BRANCH}"
as_app git -C "$APP_DIR" fetch origin "$BRANCH"
as_app git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
ok "now at $(as_app git -C "$APP_DIR" rev-parse --short HEAD)"

log "Installing PHP dependencies"
as_app composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist --working-dir="$APP_DIR"

log "Building assets"
as_app npm --prefix "$APP_DIR" ci --no-audit --no-fund
as_app npm --prefix "$APP_DIR" run build
# Fail before the swap rather than after, so the old build keeps serving.
[ -f "${APP_DIR}/public/build/manifest.json" ] || die "vite build produced no manifest — nothing was swapped."

log "Migrating and recaching"
as_app "$PHP_BIN" "${APP_DIR}/artisan" down --retry=15 || true
as_app "$PHP_BIN" "${APP_DIR}/artisan" migrate --force --no-interaction
# Idempotent: creates the platform administrator on first deploy, and only
# re-asserts the flag afterwards. It never resets an existing password.
as_app "$PHP_BIN" "${APP_DIR}/artisan" db:seed --class=SuperAdminSeeder --force --no-interaction
as_app "$PHP_BIN" "${APP_DIR}/artisan" optimize
as_app "$PHP_BIN" "${APP_DIR}/artisan" up

log "Restarting workers"
# opcache.validate_timestamps is off, so FPM must be reloaded to see new code.
systemctl reload "php${PHP_VERSION}-fpm"
systemctl restart "${APP_SLUG}-queue.service" "${APP_SLUG}-reverb.service"

ok "deployed — $(as_app git -C "$APP_DIR" rev-parse --short HEAD) live"
