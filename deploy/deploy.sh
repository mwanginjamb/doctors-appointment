#!/usr/bin/env bash
#
# Deploys one release tag of HealthFirst on this server.
#
# Installed as /usr/local/bin/healthfirst-deploy (owned by root, not writable by anyone else) and run
# by CI over SSH through sudo; see docs/FEATURES.md, "Deploying from CI". It can also be run by hand:
#
#   sudo healthfirst-deploy v1.2.0
#
# Settings can be overridden in /etc/default/healthfirst-deploy (same names as below).
#
# Steps: check the tag -> back up the database -> check out the tag -> composer install ->
# migrate -> rbac/init -> reload PHP-FPM and restart the background services -> check /health.
set -Eeuo pipefail

APP_DIR=/var/www/html/doctors-appointment
APP_USER=""                                   # empty = the owner of APP_DIR
GIT_REMOTE=origin
RELEASE_BRANCH=main                           # a tag is deployed only if it is on this branch
PHP_BIN=/usr/bin/php
COMPOSER_BIN=""                               # empty = composer from PATH
PHP_FPM_SERVICE=php8.3-fpm
APP_TARGET=doctors-appointment.target
HEALTH_URL=https://appointments.gumzpsystems.tech/health
BACKUP_DIR=/var/backups/healthfirst
KEEP_BACKUPS=10
SKIP_BACKUP=0                                 # 1 = no database backup (not recommended)

[ -r /etc/default/healthfirst-deploy ] && . /etc/default/healthfirst-deploy

TAG="${1:-}"
STEP="starting"
PREV=""
BACKUP_FILE=""

log() { printf '[deploy] %s\n' "$*"; }

where_we_are() {
    if [ -n "$PREV" ]; then printf '[deploy] The previous release was commit %s.\n' "$PREV" >&2; fi
    if [ -n "$BACKUP_FILE" ]; then printf '[deploy] Database backup taken before this deploy: %s\n' "$BACKUP_FILE" >&2; fi
}

fail() {
    trap - ERR
    printf '[deploy] ERROR: %s\n' "$*" >&2
    where_we_are
    exit 1
}

on_error() {
    local code=$?
    trap - ERR
    printf '[deploy] FAILED during: %s (exit %s)\n' "$STEP" "$code" >&2
    where_we_are
    exit "$code"
}
trap on_error ERR

# Only release tags such as v1.2.0: this also keeps anything else out of the git commands below.
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "usage: healthfirst-deploy vX.Y.Z (got '${TAG}')"
[ "$(id -u)" -eq 0 ] || fail "run as root (through sudo)"
[ -d "$APP_DIR/.git" ] || fail "$APP_DIR is not a git checkout"

APP_USER="${APP_USER:-$(stat -c %U "$APP_DIR")}"
COMPOSER_BIN="${COMPOSER_BIN:-$(command -v composer || true)}"
[ -n "$COMPOSER_BIN" ] || fail "composer not found; set COMPOSER_BIN in /etc/default/healthfirst-deploy"

# One deploy at a time.
exec 9>/run/healthfirst-deploy.lock
flock -n 9 || fail "another deploy is running"

cd "$APP_DIR"
as_app() { sudo -u "$APP_USER" -H "$@"; }

STEP="fetching $TAG"
log "Fetching $TAG from $GIT_REMOTE"
as_app git fetch --quiet --force "$GIT_REMOTE" "+refs/heads/$RELEASE_BRANCH:refs/remotes/$GIT_REMOTE/$RELEASE_BRANCH" "+refs/tags/$TAG:refs/tags/$TAG"
as_app git rev-parse --quiet --verify "refs/tags/$TAG^{commit}" >/dev/null || fail "tag $TAG not found on $GIT_REMOTE"
as_app git merge-base --is-ancestor "refs/tags/$TAG" "refs/remotes/$GIT_REMOTE/$RELEASE_BRANCH" \
    || fail "$TAG is not on $RELEASE_BRANCH; release tags must be made on $RELEASE_BRANCH"

STEP="checking the working tree"
if [ -n "$(as_app git status --porcelain --untracked-files=no)" ]; then
    as_app git status --short --untracked-files=no >&2
    fail "files tracked by git were changed on the server (above); commit or discard them first"
fi
PREV="$(as_app git rev-parse HEAD)"
if [ "$PREV" = "$(as_app git rev-parse "refs/tags/$TAG^{commit}")" ]; then
    log "$TAG is already checked out; running the remaining steps again"
fi

if [ "$SKIP_BACKUP" != "1" ]; then
    STEP="backing up the database"
    command -v mysqldump >/dev/null || fail "mysqldump not found (install the MySQL client, or set SKIP_BACKUP=1)"
    # Read the connection from .env the way the app does.
    mapfile -t DB < <(as_app "$PHP_BIN" -r '
        require "vendor/autoload.php";
        $e = Dotenv\Dotenv::createArrayBacked(getcwd())->load();
        foreach (["DB_HOST", "DB_PORT", "DB_NAME", "DB_USER", "DB_PASSWORD"] as $k) { echo ($e[$k] ?? ""), "\n"; }
    ')
    [ -n "${DB[2]:-}" ] || fail "DB_NAME is not set in $APP_DIR/.env"
    install -d -m 700 "$BACKUP_DIR"
    BACKUP_FILE="$BACKUP_DIR/${DB[2]}-$(date +%Y%m%d-%H%M%S)-before-$TAG.sql.gz"
    log "Backing up ${DB[2]} to $BACKUP_FILE"
    MYSQL_PWD="${DB[4]:-}" mysqldump --host="${DB[0]:-localhost}" --port="${DB[1]:-3306}" --user="${DB[3]:-}" \
        --single-transaction --routines --triggers --no-tablespaces "${DB[2]}" | gzip > "$BACKUP_FILE"
    chmod 600 "$BACKUP_FILE"
    # Keep the newest KEEP_BACKUPS.
    ls -1t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | tail -n +"$((KEEP_BACKUPS + 1))" | xargs -r rm -f
fi

STEP="checking out $TAG"
log "Checking out $TAG (was ${PREV:0:12})"
as_app git checkout --quiet --detach "refs/tags/$TAG"

STEP="installing dependencies"
log "composer install"
if ! as_app "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader; then
    log "composer failed; going back to ${PREV:0:12}"
    as_app git checkout --quiet --detach "$PREV"
    as_app "$PHP_BIN" "$COMPOSER_BIN" install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader || true
    fail "composer install failed for $TAG; the previous release is back in place"
fi

STEP="running migrations"
log "php yii migrate"
as_app "$PHP_BIN" yii migrate --interactive=0 --color=0

STEP="updating permissions"
log "php yii rbac/init"
as_app "$PHP_BIN" yii rbac/init --color=0

STEP="restarting services"
log "Reloading $PHP_FPM_SERVICE and restarting $APP_TARGET"
systemctl reload "$PHP_FPM_SERVICE"
systemctl restart "$APP_TARGET"

STEP="checking $HEALTH_URL"
for i in 1 2 3 4 5 6; do
    if [ "$(curl -fsS --max-time 10 "$HEALTH_URL" 2>/dev/null || true)" = "ok" ]; then
        log "Health check passed"
        log "Deployed $TAG ($(as_app git rev-parse --short HEAD))"
        exit 0
    fi
    sleep 5
done
fail "$HEALTH_URL did not answer 'ok' after the deploy"
