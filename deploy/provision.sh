#!/usr/bin/env bash
#
# Sprint Sync AI — one-shot production provisioning for a shared Ubuntu + Nginx server.
#
# Run as root on the Linode box:
#
#   DOMAIN=app.example.com \
#   LETSENCRYPT_EMAIL=you@example.com \
#   OPENAI_API_KEY=sk-... \
#   bash <(curl -fsSL https://raw.githubusercontent.com/alihassan3413/FYP_Sprint_Sync_AI/main/deploy/provision.sh)
#
# Designed for a server that already hosts other live sites. It only ever creates
# or edits resources namespaced to this app:
#
#   /var/www/<domain>                          application code
#   /etc/php/<ver>/fpm/pool.d/<slug>.conf      dedicated FPM pool + socket
#   /etc/nginx/sites-available/<domain>        one new vhost
#   /etc/systemd/system/<slug>-{queue,reverb}.service
#   /etc/cron.d/<slug>-scheduler
#
# It never edits another site's vhost, never restarts another PHP version's FPM,
# and restores the system-wide `php` alternative if installing PHP changed it.
#
# Re-running is safe and idempotent. On a second run the script:
#   * installs only apt packages that are actually missing (and only then does
#     it run `apt-get update`),
#   * keeps the existing .env, its APP_KEY, Reverb credentials, API keys and any
#     mail settings edited on the server,
#   * leaves the nginx vhost and certbot's TLS block alone unless the generated
#     template genuinely changed, and re-installs the existing certificate
#     rather than issuing a new one,
#   * reloads PHP-FPM instead of restarting it, so other pools keep serving,
#   * skips composer install / npm ci / vite build when the lockfiles, commit
#     and VITE_* values are unchanged.
#
# FORCE=1 additionally re-runs every skipped build step (and bypasses the
# "another vhost already serves this domain" guard).

set -Eeuo pipefail

# ---------------------------------------------------------------- configuration

# Which optional settings the operator passed explicitly this run. Anything not
# passed is left exactly as an existing .env already has it on a re-run.
APP_NAME_EXPLICIT="${APP_NAME+1}"
MAIL_MAILER_EXPLICIT="${MAIL_MAILER+1}"
MAIL_HOST_EXPLICIT="${MAIL_HOST+1}"
MAIL_PORT_EXPLICIT="${MAIL_PORT+1}"
MAIL_FROM_ADDRESS_EXPLICIT="${MAIL_FROM_ADDRESS+1}"

DOMAIN="${DOMAIN:-}"
REPO="${REPO:-https://github.com/alihassan3413/FYP_Sprint_Sync_AI.git}"
BRANCH="${BRANCH:-main}"
APP_SLUG="${APP_SLUG:-sprintsync}"
APP_NAME="${APP_NAME:-Sprint Sync AI}"
APP_DIR="${APP_DIR:-/var/www/${DOMAIN}}"
APP_USER="${APP_USER:-${APP_SLUG}}"
PHP_VERSION="${PHP_VERSION:-8.4}"
NODE_VERSION="${NODE_VERSION:-22.11.0}"
NODE_PREFIX="/opt/node-${NODE_VERSION}"

LETSENCRYPT_EMAIL="${LETSENCRYPT_EMAIL:-}"
SKIP_SSL="${SKIP_SSL:-0}"
FORCE="${FORCE:-0}"
SEED_DEMO="${SEED_DEMO:-0}"
ENSURE_SWAP="${ENSURE_SWAP:-0}"

OPENAI_API_KEY="${OPENAI_API_KEY:-}"
ANTHROPIC_API_KEY="${ANTHROPIC_API_KEY:-}"
MAIL_MAILER="${MAIL_MAILER:-log}"
MAIL_HOST="${MAIL_HOST:-127.0.0.1}"
MAIL_PORT="${MAIL_PORT:-2525}"
MAIL_USERNAME="${MAIL_USERNAME:-}"
MAIL_PASSWORD="${MAIL_PASSWORD:-}"
MAIL_FROM_ADDRESS="${MAIL_FROM_ADDRESS:-no-reply@${DOMAIN:-localhost}}"

PHP_BIN="/usr/bin/php${PHP_VERSION}"
FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm-${APP_SLUG}.sock"
NGINX_SITE="/etc/nginx/sites-available/${DOMAIN}"
APP_SCHEME="http"
COMPOSER_BIN="${COMPOSER_BIN:-}"
APP_HOME="/home/${APP_USER}"
# Fingerprints of the last successful expensive step, so a re-run can skip work
# whose inputs have not changed. FORCE=1 ignores them.
STATE_DIR="${APP_DIR}/storage/app/.provision"
APT_UPDATED=0
NGINX_VHOST_REWRITTEN=0

# ---------------------------------------------------------------------- helpers
log()   { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
ok()    { printf '\033[1;32m  ok\033[0m %s\n' "$*"; }
warn()  { printf '\033[1;33m  !!\033[0m %s\n' "$*" >&2; }
die()   { printf '\033[1;31merror:\033[0m %s\n' "$*" >&2; exit 1; }

trap 'printf "\033[1;31merror:\033[0m aborted at line %s. Other sites on this box were never touched; to inspect this app see /var/log/nginx/%s.error.log and %s/storage/logs/\n" "$LINENO" "$DOMAIN" "$APP_DIR" >&2' ERR

# ${APP_HOME}/bin/php is a shim pointing at ${PHP_BIN}, so anything the app
# shells out to (composer's `@php artisan` scripts, npm hooks) gets this app's
# PHP version rather than whatever /usr/bin/php happens to be for other sites.
as_app() { sudo -u "$APP_USER" -H env PATH="${APP_HOME}/bin:${NODE_PREFIX}/bin:/usr/local/bin:/usr/bin:/bin" COMPOSER_HOME="${APP_HOME}/.composer" "$@"; }

# Add or replace a key in .env, preserving every other line.
set_env() {
  local key="$1" value="$2" file="${APP_DIR}/.env" tmp
  tmp="$(mktemp)"
  KEY="$key" VALUE="$value" awk '
    BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"]; done = 0 }
    { if (index($0, k "=") == 1) { if (!done) { print k "=" v; done = 1 } } else { print } }
    END { if (!done) { print k "=" v } }
  ' "$file" > "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

get_env() {
  [ -f "${APP_DIR}/.env" ] || return 0
  grep -E "^$1=" "${APP_DIR}/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '"'
}

rand() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'; }

# ------------------------------------------------------------- package helpers
pkg_installed() {
  [ "$(dpkg-query -W -f='${db:Status-Status}' "$1" 2>/dev/null || true)" = "installed" ]
}

# apt-get update runs at most once per run, and only when something is missing.
apt_update_once() {
  if [ "$APT_UPDATED" = "1" ]; then
    return 0
  fi
  log "Refreshing apt package lists"
  apt-get update
  APT_UPDATED=1
}

# Install only the packages that are genuinely missing. Output stays visible:
# these steps take minutes and a silent terminal looks like a hung script.
# --force-confold means an upgrade never overwrites a config file another site
# on this box depends on.
apt_install() {
  local pkg missing=()
  for pkg in "$@"; do
    if ! pkg_installed "$pkg"; then
      missing+=("$pkg")
    fi
  done

  if [ "${#missing[@]}" -eq 0 ]; then
    ok "already installed: $*"
    return 0
  fi

  log "Installing ${#missing[@]} missing package(s): ${missing[*]}"
  apt_update_once
  apt-get install -y \
    -o Dpkg::Options::=--force-confdef \
    -o Dpkg::Options::=--force-confold \
    "${missing[@]}"
}

# Write stdin to a file only when the content actually differs. Returns 0 when
# the file changed, 1 when it was already correct, so callers can skip restarts.
write_if_changed() {
  local dest="$1" tmp
  tmp="$(mktemp)"
  cat > "$tmp"
  if [ -f "$dest" ] && cmp -s "$tmp" "$dest"; then
    rm -f "$tmp"
    return 1
  fi
  install -m 0644 "$tmp" "$dest"
  rm -f "$tmp"
  return 0
}

# Has an expensive step already succeeded for exactly these inputs?
stamp_current() {
  if [ "$FORCE" = "1" ]; then
    return 1
  fi
  [ -f "${STATE_DIR}/$1" ] && [ "$(cat "${STATE_DIR}/$1" 2>/dev/null)" = "$2" ]
}

stamp_write() {
  mkdir -p "$STATE_DIR"
  printf '%s\n' "$2" > "${STATE_DIR}/$1"
  chown -R "${APP_USER}:${APP_USER}" "$STATE_DIR" 2>/dev/null || true
}

# ------------------------------------------------------------------- preflight
preflight() {
  log "Preflight checks"

  [ "$(id -u)" -eq 0 ] || die "run this as root (sudo -i)."
  [ -n "$DOMAIN" ]     || die "DOMAIN is required, e.g. DOMAIN=app.example.com"
  [ -n "$APP_DIR" ]    || die "APP_DIR resolved empty."

  command -v nginx >/dev/null || die "nginx not found. This script targets a plain nginx server."

  # install_node rm -rf's this path, so never let a bad override point it at
  # something that matters on a shared box.
  case "$NODE_PREFIX" in
    /opt/node-?*) ;;
    *) die "NODE_PREFIX must look like /opt/node-<version> (got '${NODE_PREFIX}')." ;;
  esac

  for panel in /usr/local/cpanel /usr/local/psa /usr/local/CyberCP /usr/local/directadmin; do
    [ -d "$panel" ] && die "control panel detected at ${panel}. Writing vhosts by hand would fight the panel — provision through it instead."
  done

  if [ -f /etc/os-release ]; then
    . /etc/os-release
    ok "host is ${PRETTY_NAME:-unknown}"
    case "${VERSION_ID:-}" in
      20.04) warn "Ubuntu 20.04 reached end of standard support in April 2025 — security updates need Ubuntu Pro/ESM. Provisioning continues." ;;
    esac
  fi

  # Refuse to hijack a domain another vhost already answers for.
  if [ "$FORCE" != "1" ]; then
    local clash
    clash="$(grep -rlE "^[[:space:]]*server_name[^;]*[[:space:]]${DOMAIN}([[:space:];]|$)" /etc/nginx/sites-enabled/ 2>/dev/null | grep -v "/${DOMAIN}\$" || true)"
    [ -n "$clash" ] && die "another nginx vhost already serves ${DOMAIN}: ${clash}. Re-run with FORCE=1 only if you are sure."
  fi

  local mem_kb swap_kb
  mem_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"
  swap_kb="$(awk '/SwapTotal/ {print $2}' /proc/meminfo)"
  if [ "$mem_kb" -lt 2000000 ] && [ "$swap_kb" -lt 512000 ]; then
    if [ "$ENSURE_SWAP" = "1" ]; then
      log "Creating 2G swapfile (low RAM, no swap present)"
      fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile
      grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
      ok "swap enabled"
    else
      warn "under 2GB RAM and no swap — 'npm run build' may get OOM-killed. Re-run with ENSURE_SWAP=1 to add a 2G swapfile."
    fi
  fi

  ok "preflight passed"
}

# ------------------------------------------------------------------- packages
install_packages() {
  log "Checking base packages"

  export DEBIAN_FRONTEND=noninteractive
  # List services that want a restart, never restart them for us: another site's
  # daemon must not be bounced as a side effect of provisioning this one.
  export NEEDRESTART_MODE=l

  apt_install git curl unzip xz-utils ca-certificates acl iproute2

  # Remember the current system-wide `php` so other sites keep the version they expect.
  local previous_php=""
  if command -v php >/dev/null; then
    previous_php="$(readlink -f "$(command -v php)")"
  fi

  local php_packages=(
    "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-cli" "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip"
    "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-intl" "php${PHP_VERSION}-gd"
    "php${PHP_VERSION}-sqlite3" "php${PHP_VERSION}-opcache" "php${PHP_VERSION}-readline"
  )

  local pkg php_missing=0
  for pkg in "${php_packages[@]}"; do
    if ! pkg_installed "$pkg"; then
      php_missing=1
      break
    fi
  done

  # Ubuntu 24.04 ships PHP 8.3, so 8.4 needs ondrej/php. Only touch apt sources
  # when something is missing AND the configured archives cannot supply it.
  if [ "$php_missing" = "1" ] && ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
    if grep -rqs "ondrej/php\|ondrej-ubuntu-php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null; then
      ok "ppa:ondrej/php already configured"
      apt_update_once
    else
      log "Adding ppa:ondrej/php (PHP ${PHP_VERSION} is not in the Ubuntu archive)"
      apt_install software-properties-common
      add-apt-repository -y ppa:ondrej/php
      APT_UPDATED=0
      apt_update_once
    fi
  fi

  apt_install "${php_packages[@]}"

  [ -x "$PHP_BIN" ] || die "PHP ${PHP_VERSION} did not install."

  # Installing a new PHP can repoint /usr/bin/php. Put it back where it was.
  if [ -n "$previous_php" ] && [ "$(readlink -f "$(command -v php)")" != "$previous_php" ]; then
    update-alternatives --set php "$previous_php" >/dev/null 2>&1 || true
    warn "restored system-wide 'php' to ${previous_php} (this app always calls ${PHP_BIN} explicitly)"
  fi

  ok "$("$PHP_BIN" -v | head -1)"
}

install_composer() {
  if [ -z "$COMPOSER_BIN" ] && command -v composer >/dev/null; then
    COMPOSER_BIN="$(command -v composer)"
  fi

  if [ -n "$COMPOSER_BIN" ] && [ -x "$COMPOSER_BIN" ]; then
    ok "composer already present at ${COMPOSER_BIN}"
    return
  fi

  log "Installing Composer"
  local setup=/tmp/composer-setup.php expected actual
  curl -fsSL https://getcomposer.org/installer -o "$setup"
  expected="$(curl -fsSL https://composer.github.io/installer.sig)"
  actual="$("$PHP_BIN" -r "echo hash_file('sha384', '${setup}');")"
  if [ -z "$expected" ] || [ "$expected" != "$actual" ]; then
    rm -f "$setup"
    die "composer installer checksum mismatch — refusing to execute it."
  fi
  "$PHP_BIN" "$setup" --install-dir=/usr/local/bin --filename=composer
  rm -f "$setup"
  COMPOSER_BIN=/usr/local/bin/composer
  ok "composer installed"
}

install_node() {
  if [ -x "${NODE_PREFIX}/bin/node" ]; then
    ok "node $("${NODE_PREFIX}/bin/node" -v) already at ${NODE_PREFIX}"
    return
  fi

  log "Installing Node ${NODE_VERSION} into ${NODE_PREFIX} (isolated — the system node other sites use is untouched)"

  local tarball="node-v${NODE_VERSION}-linux-x64.tar.xz" tmp
  tmp="$(mktemp -d)"
  curl -fSL --progress-bar "https://nodejs.org/dist/v${NODE_VERSION}/${tarball}" -o "${tmp}/${tarball}"
  curl -fsSL "https://nodejs.org/dist/v${NODE_VERSION}/SHASUMS256.txt" -o "${tmp}/SHASUMS256.txt"
  if ! ( cd "$tmp" && grep " ${tarball}\$" SHASUMS256.txt | sha256sum -c - ); then
    rm -rf "$tmp"
    die "node tarball checksum mismatch — refusing to install it."
  fi

  # Unpack beside the target and move it into place, so an interrupted run never
  # leaves a half-populated ${NODE_PREFIX} that the next run mistakes for done.
  mkdir -p "${tmp}/unpack"
  tar -xJf "${tmp}/${tarball}" -C "${tmp}/unpack" --strip-components=1
  rm -rf "${NODE_PREFIX}.partial" "$NODE_PREFIX"
  mkdir -p "$(dirname "$NODE_PREFIX")"
  mv "${tmp}/unpack" "${NODE_PREFIX}.partial"
  rm -rf "$tmp"
  mv "${NODE_PREFIX}.partial" "$NODE_PREFIX"
  ok "$("${NODE_PREFIX}/bin/node" -v)"
}

# ------------------------------------------------------------------ app layout
create_user() {
  if id "$APP_USER" >/dev/null 2>&1; then
    ok "user ${APP_USER} exists"
  else
    log "Creating system user ${APP_USER}"
    useradd --system --create-home --home-dir "$APP_HOME" --shell /bin/bash "$APP_USER"
    ok "user created"
  fi

  if ! id -nG www-data | tr ' ' '\n' | grep -qx "$APP_USER"; then
    usermod -aG "$APP_USER" www-data
  fi

  mkdir -p "${APP_HOME}/.composer" "${APP_HOME}/bin"
  # See as_app(): pin `php` for everything this app shells out to.
  ln -sfn "$PHP_BIN" "${APP_HOME}/bin/php"
  chown -R "${APP_USER}:${APP_USER}" "$APP_HOME"
}

fetch_code() {
  if [ -d "${APP_DIR}/.git" ]; then
    log "Updating existing checkout in ${APP_DIR}"
    # A checkout made by hand is almost always root-owned. Hand it to the app
    # user before running git, or every write from here on is permission-denied.
    chown -R "${APP_USER}:${APP_USER}" "$APP_DIR"
    as_app git -C "$APP_DIR" fetch origin "$BRANCH"
    as_app git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
  elif [ -d "$APP_DIR" ] && [ -n "$(ls -A "$APP_DIR" 2>/dev/null)" ]; then
    die "${APP_DIR} already has files but no .git — git clone cannot write into it. Either move it aside, or point APP_DIR at the real checkout."
  else
    log "Cloning ${REPO} (${BRANCH}) into ${APP_DIR}"
    mkdir -p "$APP_DIR"
    chown "${APP_USER}:${APP_USER}" "$APP_DIR"
    as_app git clone --depth 1 --branch "$BRANCH" "$REPO" "$APP_DIR"
  fi

  # --add would append a duplicate line to ~/.gitconfig on every single run.
  if ! as_app git config --global --get-all safe.directory 2>/dev/null | grep -qx "$APP_DIR"; then
    as_app git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true
  fi

  ok "code at $(as_app git -C "$APP_DIR" rev-parse --short HEAD)"
}

# -------------------------------------------------------------------- php-fpm
write_fpm_pool() {
  log "Checking PHP-FPM pool"

  # FPM runs as ${APP_USER} and logs here, so the directory must exist and be
  # writable by it before the pool starts.
  mkdir -p "${APP_DIR}/storage/logs"
  chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}/storage/logs"

  local pool="/etc/php/${PHP_VERSION}/fpm/pool.d/${APP_SLUG}.conf"
  local changed=0

  if write_if_changed "$pool" <<POOL_EOF
; Managed by deploy/provision.sh for ${DOMAIN} — do not edit by hand.
[${APP_SLUG}]
user = ${APP_USER}
group = ${APP_USER}

listen = ${FPM_SOCKET}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 12
pm.start_servers = 3
pm.min_spare_servers = 2
pm.max_spare_servers = 5
pm.max_requests = 500

request_terminate_timeout = 300

php_admin_value[memory_limit] = 512M
php_admin_value[upload_max_filesize] = 60M
php_admin_value[post_max_size] = 64M
php_admin_value[max_execution_time] = 300
php_admin_value[expose_php] = Off
php_admin_value[error_log] = ${APP_DIR}/storage/logs/php-fpm.log
php_admin_flag[log_errors] = on
php_admin_flag[display_errors] = off

php_admin_value[opcache.enable] = 1
php_admin_value[opcache.memory_consumption] = 192
php_admin_value[opcache.max_accelerated_files] = 20000
php_admin_value[opcache.validate_timestamps] = 0

php_admin_value[open_basedir] = ${APP_DIR}:/tmp:/usr/share/php:/dev/urandom:/etc/ssl/certs:/usr/share/ca-certificates
POOL_EOF
  then
    changed=1
  fi

  systemctl enable "php${PHP_VERSION}-fpm" >/dev/null 2>&1 || true

  if [ "$changed" = "0" ] && systemctl is-active --quiet "php${PHP_VERSION}-fpm"; then
    ok "pool ${APP_SLUG} already configured on ${FPM_SOCKET}"
    return
  fi

  "php-fpm${PHP_VERSION}" -t || die "php-fpm config test failed — pool not applied."

  if systemctl is-active --quiet "php${PHP_VERSION}-fpm"; then
    # Reload, not restart: other pools on this PHP version keep serving.
    systemctl reload "php${PHP_VERSION}-fpm"
  else
    systemctl start "php${PHP_VERSION}-fpm"
  fi

  ok "pool ${APP_SLUG} listening on ${FPM_SOCKET}"
}

# ---------------------------------------------------------------------- nginx
pick_reverb_port() {
  local existing
  existing="$(get_env REVERB_SERVER_PORT)"
  if [ -n "$existing" ]; then
    echo "$existing"
    return
  fi
  local port
  for port in $(seq 9080 9200); do
    if ! ss -lnt "( sport = :${port} )" 2>/dev/null | grep -q LISTEN; then
      echo "$port"
      return
    fi
  done
  die "no free port in 9080-9200 for Reverb."
}

write_nginx_site() {
  local reverb_port="$1"
  log "Checking nginx vhost for ${DOMAIN}"

  local body fingerprint
  body="$(mktemp)"
  cat > "$body" <<NGINX_EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;

    client_max_body_size 60M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    gzip on;
    gzip_comp_level 5;
    gzip_min_length 256;
    gzip_types text/plain text/css application/json application/javascript text/javascript application/xml image/svg+xml;

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    # Vite build output is content-hashed and safe to cache hard.
    location /build/ {
        expires 1y;
        access_log off;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    # Reverb websocket handshake (browser -> wss://${DOMAIN}/app/<key>)
    location /app {
        proxy_pass http://127.0.0.1:${reverb_port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }

    # Reverb pusher-compatible HTTP API
    location /apps {
        proxy_pass http://127.0.0.1:${reverb_port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_pass unix:${FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX_EOF

  fingerprint="$(sha256sum "$body" | cut -c1-16)"

  # certbot edits this file in place to add its TLS directives. Regenerating the
  # plain-HTTP template on every run would tear HTTPS off a live site, so only
  # rewrite when the template we would produce has genuinely changed.
  if [ -f "$NGINX_SITE" ] \
     && grep -q "provision-fingerprint: ${fingerprint}" "$NGINX_SITE" \
     && [ -L "/etc/nginx/sites-enabled/${DOMAIN}" ]; then
    rm -f "$body"
    NGINX_VHOST_REWRITTEN=0
    ok "vhost already current — certbot's TLS block (if any) left untouched"
    return
  fi

  local backup=""
  if [ -f "$NGINX_SITE" ]; then
    backup="${NGINX_SITE}.bak.$(date +%s)"
    cp "$NGINX_SITE" "$backup"
    warn "vhost template changed — regenerating ${NGINX_SITE} (backup: ${backup})"
  fi

  {
    echo "# Managed by deploy/provision.sh for ${DOMAIN} — certbot may append TLS directives."
    echo "# provision-fingerprint: ${fingerprint}"
    cat "$body"
  } > "$NGINX_SITE"
  rm -f "$body"
  chmod 644 "$NGINX_SITE"
  NGINX_VHOST_REWRITTEN=1

  ln -sfn "$NGINX_SITE" "/etc/nginx/sites-enabled/${DOMAIN}"

  if ! nginx -t; then
    rm -f "/etc/nginx/sites-enabled/${DOMAIN}"
    if [ -n "$backup" ]; then
      mv "$backup" "$NGINX_SITE"
      ln -sfn "$NGINX_SITE" "/etc/nginx/sites-enabled/${DOMAIN}"
    else
      rm -f "$NGINX_SITE"
    fi
    nginx -t || true
    die "nginx config test failed — our vhost was rolled back and every other site is still serving."
  fi

  systemctl reload nginx
  [ -n "$backup" ] && rm -f "$backup"
  ok "vhost enabled and nginx reloaded"
}

setup_ssl() {
  if [ "$SKIP_SSL" = "1" ]; then
    warn "SKIP_SSL=1 — serving plain HTTP. Websockets will use ws://, not wss://."
    return
  fi

  # A certificate already issued for this domain is reused rather than re-issued:
  # Let's Encrypt rate-limits duplicate issuance, and a re-run must never cost a
  # cert. It only has to be re-installed if we just regenerated the vhost.
  if [ -d "/etc/letsencrypt/live/${DOMAIN}" ] && command -v certbot >/dev/null; then
    if [ "$NGINX_VHOST_REWRITTEN" = "0" ]; then
      APP_SCHEME="https"
      ok "existing certificate for ${DOMAIN} left in place"
      return
    fi
    log "Re-installing the existing certificate into the regenerated vhost"
    if certbot install --cert-name "$DOMAIN" --nginx --non-interactive --redirect; then
      APP_SCHEME="https"
      ok "HTTPS restored from the existing certificate"
      return
    fi
    warn "could not re-install the existing certificate — falling through to a normal issuance attempt."
  fi

  if [ -z "$LETSENCRYPT_EMAIL" ]; then
    warn "LETSENCRYPT_EMAIL not set — skipping TLS. Re-run with it to enable HTTPS."
    return
  fi

  log "Checking DNS for ${DOMAIN}"
  local resolved server_ip
  resolved="$(getent hosts "$DOMAIN" | awk '{print $1}' | head -1 || true)"
  server_ip="$(curl -fsSL --max-time 10 https://api.ipify.org 2>/dev/null || true)"
  if [ -z "$resolved" ]; then
    warn "${DOMAIN} does not resolve yet — skipping TLS. Point the A record at this server, then re-run."
    return
  fi
  if [ -n "$server_ip" ] && [ "$resolved" != "$server_ip" ]; then
    warn "${DOMAIN} resolves to ${resolved} but this server is ${server_ip} — skipping TLS to avoid a failed ACME challenge."
    return
  fi
  ok "DNS points here (${resolved})"

  if ! command -v certbot >/dev/null; then
    log "Installing certbot"
    if command -v snap >/dev/null; then
      snap install --classic certbot
      ln -sf /snap/bin/certbot /usr/bin/certbot
    else
      apt_install certbot python3-certbot-nginx
    fi
  fi

  log "Requesting Let's Encrypt certificate"
  if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$LETSENCRYPT_EMAIL" --redirect --keep-until-expiring; then
    APP_SCHEME="https"
    ok "HTTPS active, renewal handled by the certbot timer"
  else
    warn "certbot failed — continuing on HTTP. Fix DNS/firewall and run: certbot --nginx -d ${DOMAIN}"
  fi
}

# ------------------------------------------------------------------------- env
# Write a value only when the operator passed it explicitly, or the key has no
# answer in .env yet. Keeps hand edits on the server from being reverted.
set_env_unless_present() {
  local key="$1" value="$2" explicit="${3:-}"
  if [ "$explicit" = "1" ] || [ -z "$(get_env "$key")" ]; then
    set_env "$key" "$value"
  fi
}

# Must run before composer: composer's post-install scripts boot Laravel, which
# expects a .env to be there. Never overwrites an existing one.
seed_env_file() {
  if [ -f "${APP_DIR}/.env" ]; then
    ok "existing .env kept — its secrets are preserved"
  else
    log "Creating .env from .env.example"
    as_app cp "${APP_DIR}/.env.example" "${APP_DIR}/.env"
  fi
  chown "${APP_USER}:${APP_USER}" "${APP_DIR}/.env"
  chmod 640 "${APP_DIR}/.env"
}

write_env() {
  local reverb_port="$1"
  log "Writing production .env"

  local ws_scheme="ws"
  local ws_port="80"
  if [ "$APP_SCHEME" = "https" ]; then
    ws_scheme="https"
    ws_port="443"
  fi

  set_env_unless_present APP_NAME "\"${APP_NAME}\"" "$APP_NAME_EXPLICIT"
  set_env APP_ENV production
  set_env APP_DEBUG false
  set_env APP_URL "${APP_SCHEME}://${DOMAIN}"
  set_env LOG_CHANNEL stack
  set_env LOG_STACK daily
  set_env LOG_LEVEL warning

  set_env DB_CONNECTION sqlite
  set_env DB_DATABASE "${APP_DIR}/database/database.sqlite"
  # SQLite under a web + queue + websocket workload needs WAL, or writers block each other.
  set_env DB_JOURNAL_MODE WAL
  set_env DB_SYNCHRONOUS NORMAL
  set_env DB_BUSY_TIMEOUT 10000

  # File-backed cache and sessions keep per-request SQLite writes off the hot path.
  set_env SESSION_DRIVER file
  set_env SESSION_DOMAIN "${DOMAIN}"
  set_env SESSION_SECURE_COOKIE "$([ "$APP_SCHEME" = "https" ] && echo true || echo false)"
  set_env CACHE_STORE file
  set_env QUEUE_CONNECTION database
  set_env FILESYSTEM_DISK local

  # Generate broadcast credentials once, then never churn them (clients cache the key).
  [ -n "$(get_env REVERB_APP_ID)" ]     || set_env REVERB_APP_ID "$(shuf -i 100000-999999 -n 1)"
  [ -n "$(get_env REVERB_APP_KEY)" ]    || set_env REVERB_APP_KEY "$(rand)"
  [ -n "$(get_env REVERB_APP_SECRET)" ] || set_env REVERB_APP_SECRET "$(rand)"

  set_env BROADCAST_CONNECTION reverb
  # Server-side broadcasts go straight to the loopback listener, bypassing nginx and TLS.
  set_env REVERB_HOST 127.0.0.1
  set_env REVERB_PORT "$reverb_port"
  set_env REVERB_SCHEME http
  set_env REVERB_SERVER_HOST 127.0.0.1
  set_env REVERB_SERVER_PORT "$reverb_port"

  # Browsers connect through nginx on the public domain instead.
  set_env VITE_APP_NAME "\"\${APP_NAME}\""
  set_env VITE_REVERB_APP_KEY "\"\${REVERB_APP_KEY}\""
  set_env VITE_REVERB_HOST "${DOMAIN}"
  set_env VITE_REVERB_PORT "$ws_port"
  set_env VITE_REVERB_SCHEME "$ws_scheme"

  # Mail settings are only written when passed explicitly or still unanswered —
  # otherwise a re-run would silently revert SMTP details edited on the server.
  set_env_unless_present MAIL_MAILER "$MAIL_MAILER" "$MAIL_MAILER_EXPLICIT"
  set_env_unless_present MAIL_HOST "$MAIL_HOST" "$MAIL_HOST_EXPLICIT"
  set_env_unless_present MAIL_PORT "$MAIL_PORT" "$MAIL_PORT_EXPLICIT"
  if [ -n "$MAIL_USERNAME" ]; then
    set_env MAIL_USERNAME "$MAIL_USERNAME"
  fi
  if [ -n "$MAIL_PASSWORD" ]; then
    set_env MAIL_PASSWORD "$MAIL_PASSWORD"
  fi
  set_env_unless_present MAIL_FROM_ADDRESS "\"${MAIL_FROM_ADDRESS}\"" "$MAIL_FROM_ADDRESS_EXPLICIT"
  set_env MAIL_FROM_NAME "\"\${APP_NAME}\""

  # Only overwrite an API key when one was actually supplied this run.
  if [ -n "$OPENAI_API_KEY" ]; then
    set_env OPENAI_API_KEY "$OPENAI_API_KEY"
  fi
  if [ -n "$ANTHROPIC_API_KEY" ]; then
    set_env ANTHROPIC_API_KEY "$ANTHROPIC_API_KEY"
  fi

  if [ -z "$(get_env OPENAI_API_KEY)" ]; then
    warn "OPENAI_API_KEY is empty — transcription and the assistant will fail until you set it in ${APP_DIR}/.env"
  fi

  if [ -z "$(get_env APP_KEY)" ]; then
    as_app "$PHP_BIN" "${APP_DIR}/artisan" key:generate --force --no-interaction
    ok "APP_KEY generated"
  else
    ok "APP_KEY preserved"
  fi
}

# ----------------------------------------------------------------------- build
# Composer must complete before ANY artisan command: on a fresh box
# vendor/autoload.php does not exist yet and artisan dies on line 1.
install_php_dependencies() {
  local fingerprint
  fingerprint="$(sha256sum "${APP_DIR}/composer.lock" | cut -c1-16)"

  if [ -f "${APP_DIR}/vendor/autoload.php" ] && stamp_current composer "$fingerprint"; then
    ok "composer dependencies already match composer.lock"
  else
    log "Installing PHP dependencies (composer install)"
    as_app "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --optimize-autoloader \
      --no-interaction --prefer-dist --working-dir="$APP_DIR"
    [ -f "${APP_DIR}/vendor/autoload.php" ] || die "composer install did not produce vendor/autoload.php."
    stamp_write composer "$fingerprint"
    ok "vendor/ ready"
  fi

  # Caches written by the previous release would be read by the new code.
  as_app "$PHP_BIN" "${APP_DIR}/artisan" optimize:clear --no-interaction >/dev/null 2>&1 || true
}

build_frontend() {
  local lock_fp head vite_fp build_fp
  lock_fp="$(sha256sum "${APP_DIR}/package-lock.json" | cut -c1-16)"

  if [ -d "${APP_DIR}/node_modules" ] && stamp_current npm "$lock_fp"; then
    ok "node_modules already match package-lock.json"
  else
    log "Installing node modules (npm ci)"
    as_app env NODE_OPTIONS=--max-old-space-size=1024 npm --prefix "$APP_DIR" ci --no-audit --no-fund
    stamp_write npm "$lock_fp"
  fi

  # Vite bakes VITE_* values into the bundle, so a changed .env means a rebuild
  # even when the commit is identical.
  head="$(as_app git -C "$APP_DIR" rev-parse HEAD)"
  vite_fp="$(grep -E '^VITE_' "${APP_DIR}/.env" 2>/dev/null | sha256sum | cut -c1-16)"
  build_fp="${head}-${lock_fp}-${vite_fp}"

  if [ -f "${APP_DIR}/public/build/manifest.json" ] && stamp_current build "$build_fp"; then
    ok "assets already built for this commit and .env"
    return
  fi

  log "Building frontend assets (this is the slow step)"
  as_app env NODE_OPTIONS=--max-old-space-size=1024 npm --prefix "$APP_DIR" run build
  [ -f "${APP_DIR}/public/build/manifest.json" ] || die "vite build produced no manifest."
  stamp_write build "$build_fp"
  ok "assets built"
}

prepare_database() {
  log "Preparing SQLite database"
  as_app mkdir -p "${APP_DIR}/database"
  if [ ! -f "${APP_DIR}/database/database.sqlite" ]; then
    as_app touch "${APP_DIR}/database/database.sqlite"
  fi
  as_app "$PHP_BIN" "${APP_DIR}/artisan" migrate --force --no-interaction

  if [ "$SEED_DEMO" = "1" ]; then
    warn "SEED_DEMO=1 — inserting demo users (test@example.com etc). Never do this on a real production site."
    as_app "$PHP_BIN" "${APP_DIR}/artisan" db:seed --force --no-interaction
  fi

  as_app "$PHP_BIN" "${APP_DIR}/artisan" storage:link --no-interaction || true
  ok "database ready"
}

optimize_app() {
  log "Caching config, routes, views and events"
  as_app "$PHP_BIN" "${APP_DIR}/artisan" optimize
  ok "application optimized"
}

fix_permissions() {
  log "Setting ownership and permissions"
  chown -R "${APP_USER}:${APP_USER}" "$APP_DIR"

  # Skip node_modules/.git/vendor: huge, already correctly owned, and their
  # bin stubs must keep the exec bit that a blanket chmod 644 would strip.
  # .env is pruned too: without it the blanket chmod 644 below would briefly
  # expose the app secrets to every other user on this shared box.
  find "$APP_DIR" \
    \( -path "${APP_DIR}/node_modules" -o -path "${APP_DIR}/.git" -o -path "${APP_DIR}/vendor" -o -path "${APP_DIR}/.env" \) -prune -o \
    -type d -exec chmod 755 {} + -o \
    -type f -exec chmod 644 {} +

  chmod 640 "${APP_DIR}/.env"
  chmod 755 "${APP_DIR}/artisan"
  chmod 755 "${APP_DIR}"/deploy/*.sh 2>/dev/null || true

  # FPM runs as ${APP_USER}; nginx (www-data) only needs to read public/.
  chmod -R ug+rwX "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" "${APP_DIR}/database"
  setfacl -R -m u:www-data:rX "${APP_DIR}/public" 2>/dev/null || true
  setfacl -m u:www-data:x "$APP_DIR" 2>/dev/null || true
  ok "permissions applied"
}

# -------------------------------------------------------------------- services
write_services() {
  local reverb_port="$1"
  log "Checking systemd units"

  local units_changed=0

  if write_if_changed "/etc/systemd/system/${APP_SLUG}-queue.service" <<QUEUE_EOF
[Unit]
Description=${APP_NAME} queue worker (${DOMAIN})
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --backoff=10 --max-time=3600 --timeout=600
Restart=always
RestartSec=5
StandardOutput=append:${APP_DIR}/storage/logs/queue.log
StandardError=append:${APP_DIR}/storage/logs/queue.log

[Install]
WantedBy=multi-user.target
QUEUE_EOF
  then
    units_changed=1
  fi

  if write_if_changed "/etc/systemd/system/${APP_SLUG}-reverb.service" <<REVERB_EOF
[Unit]
Description=${APP_NAME} Reverb websocket server (${DOMAIN})
After=network.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/artisan reverb:start --host=127.0.0.1 --port=${reverb_port}
Restart=always
RestartSec=5
StandardOutput=append:${APP_DIR}/storage/logs/reverb.log
StandardError=append:${APP_DIR}/storage/logs/reverb.log

[Install]
WantedBy=multi-user.target
REVERB_EOF
  then
    units_changed=1
  fi

  write_if_changed "/etc/cron.d/${APP_SLUG}-scheduler" <<CRON_EOF || true
# ${APP_NAME} scheduler (${DOMAIN}) — managed by deploy/provision.sh
# ${APP_NAME} scheduler (${DOMAIN}) — managed by deploy/provision.sh
* * * * * ${APP_USER} cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> ${APP_DIR}/storage/logs/scheduler.log 2>&1
CRON_EOF

  if [ "$units_changed" = "1" ]; then
    systemctl daemon-reload
  fi

  systemctl enable "${APP_SLUG}-queue.service" "${APP_SLUG}-reverb.service" >/dev/null
  # Always restart: even with unchanged units, the workers are holding the old
  # code in memory after a deploy.
  systemctl restart "${APP_SLUG}-queue.service" "${APP_SLUG}-reverb.service"
  ok "queue worker, reverb and scheduler running"
}

verify() {
  log "Verifying"
  sleep 3

  systemctl is-active --quiet "${APP_SLUG}-queue.service"  && ok "queue worker active"  || warn "queue worker not active — journalctl -u ${APP_SLUG}-queue"
  systemctl is-active --quiet "${APP_SLUG}-reverb.service" && ok "reverb active"        || warn "reverb not active — journalctl -u ${APP_SLUG}-reverb"
  systemctl is-active --quiet "php${PHP_VERSION}-fpm"      && ok "php-fpm active"       || warn "php-fpm not active"

  local code
  code="$(curl -fsS -o /dev/null -w '%{http_code}' --max-time 15 "${APP_SCHEME}://${DOMAIN}/up" 2>/dev/null || echo 000)"
  if [ "$code" = "200" ]; then
    ok "health check ${APP_SCHEME}://${DOMAIN}/up returned 200"
  else
    warn "health check returned ${code} — check /var/log/nginx/${DOMAIN}.error.log and ${APP_DIR}/storage/logs/"
  fi
}

summary() {
  cat <<SUMMARY_EOF

$(printf '\033[1;32m')Deployment complete$(printf '\033[0m')

  URL            ${APP_SCHEME}://${DOMAIN}
  Code           ${APP_DIR}
  PHP            ${PHP_BIN}  (pool: ${APP_SLUG}, socket: ${FPM_SOCKET})
  Database       ${APP_DIR}/database/database.sqlite  (WAL)
  Services       systemctl status ${APP_SLUG}-queue ${APP_SLUG}-reverb
  Logs           ${APP_DIR}/storage/logs/  and  /var/log/nginx/${DOMAIN}.*.log

Redeploy after a push:

  bash ${APP_DIR}/deploy/deploy.sh

Back up the database:

  ${PHP_BIN} ${APP_DIR}/artisan down && cp ${APP_DIR}/database/database.sqlite /root/backup-\$(date +%F).sqlite && ${PHP_BIN} ${APP_DIR}/artisan up

SUMMARY_EOF
}

# ------------------------------------------------------------------------ main
main() {
  preflight
  install_packages
  install_composer
  install_node
  create_user
  fetch_code

  local reverb_port
  reverb_port="$(pick_reverb_port)"
  log "Reverb will listen on 127.0.0.1:${reverb_port}"

  write_fpm_pool
  write_nginx_site "$reverb_port"
  setup_ssl

  # Order matters and is the thing most likely to bite on a fresh box:
  #   .env exists -> composer install -> only then any artisan command.
  # `artisan key:generate` inside write_env needs vendor/autoload.php.
  seed_env_file
  install_php_dependencies
  write_env "$reverb_port"

  build_frontend
  prepare_database
  optimize_app
  fix_permissions
  write_services "$reverb_port"

  # TLS may have been added after the vhost was written; make sure nginx has it all.
  if nginx -t >/dev/null 2>&1; then
    systemctl reload nginx
  else
    warn "nginx -t is failing — skipped the final reload. Run 'nginx -t' to see why."
  fi

  verify
  summary
}

main "$@"
