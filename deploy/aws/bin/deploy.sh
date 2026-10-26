#!/usr/bin/env bash
# Builds a new release next to the running one and switches to it only when
# it is ready. If the health check fails, the previous release comes back.
#
#   sudo -u caderneta caderneta-deploy --ref main             # code only
#   sudo -u caderneta caderneta-deploy --ref main --migrate   # also pending migrations
#   sudo -u caderneta caderneta-deploy --ref v1.2.0 --with-pwa
#
# Migrations never run without --migrate and a typed confirmation, and they
# run as agenda_owner; the application keeps connecting as agenda_app.

set -euo pipefail

APP_DIR=/var/www/caderneta-sef
REPOSITORY=${CADERNETA_REPOSITORY:-https://github.com/holid4y/CadernetaSEF.git}
ENV_FILE=$APP_DIR/shared/.env
OWNER_FILE=$APP_DIR/shared/db-owner.env
KEEP_RELEASES=5
REF=main MIGRATE=0 PWA=0 ASSUME_YES=0

fail() { echo "deploy: $*" >&2; exit 1; }
step() { echo "==> $*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --ref) REF=$2; shift 2 ;;
        --migrate) MIGRATE=1; shift ;;
        --with-pwa) PWA=1; shift ;;
        --yes) ASSUME_YES=1; shift ;;
        *) fail "unknown option $1" ;;
    esac
done

env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

step "Checking the server"
[ "$(id -un)" = caderneta ] || fail "run it as caderneta: sudo -u caderneta caderneta-deploy ..."
for command in git php composer npm node psql curl; do
    command -v "$command" >/dev/null || fail "$command is not installed; run provision.sh."
done
[ -f "$ENV_FILE" ] || fail "$ENV_FILE is missing; run provision.sh."
[ "$(env_value APP_ENV)" = production ] || fail "APP_ENV must be production in $ENV_FILE."
[ "$(env_value APP_DEBUG)" = false ] || fail "APP_DEBUG must be false in $ENV_FILE."
[ -n "$(env_value APP_KEY)" ] || fail "APP_KEY is empty in $ENV_FILE."
[ "$(env_value DB_SSLMODE)" = verify-full ] || fail "DB_SSLMODE must be verify-full in $ENV_FILE."
[ -r "$(env_value DB_SSLROOTCERT)" ] || fail "the RDS certificate bundle $(env_value DB_SSLROOTCERT) is missing."
[ "$(env_value DB_USERNAME)" = agenda_app ] || fail "the application must connect as agenda_app; run caderneta-database-setup."
[ -n "$(env_value DB_PASSWORD)" ] || fail "DB_PASSWORD is empty; run caderneta-database-setup."
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
APP_URL=$(env_value APP_URL)
HOST=${APP_URL#https://}
HOST=${HOST%%/*}

PREVIOUS=''
[ ! -L "$APP_DIR/current" ] || PREVIOUS=$(readlink -f "$APP_DIR/current")
RELEASE=$APP_DIR/releases/$(date -u +%Y%m%d%H%M%S)

step "Fetching $REF"
git clone --quiet --depth 1 --branch "$REF" "$REPOSITORY" "$RELEASE"
echo "Commit $(git -C "$RELEASE" rev-parse --short HEAD)"
rm -rf "$RELEASE/storage"
ln -s "$APP_DIR/shared/storage" "$RELEASE/storage"
ln -s "$ENV_FILE" "$RELEASE/.env"
cd "$RELEASE"

step "PHP dependencies"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress

step "Web assets"
npm ci --no-audit --no-fund --loglevel=error
npm run build
rm -rf node_modules

if [ "$PWA" -eq 1 ]; then
    step "Mobile app (PWA in server mode at $APP_URL/app/)"
    npm --prefix mobile ci --no-audit --no-fund --loglevel=error
    MOBILE_API_URL="$APP_URL/api/v1" npm --prefix mobile run build:server
    cp -R mobile/dist public/app
    rm -rf mobile/node_modules
fi

psql_as() {
    local user=$1 password=$2
    shift 2
    PGPASSWORD=$password psql "host=$(env_value DB_HOST) port=$(env_value DB_PORT) dbname=$(env_value DB_DATABASE) user=$user sslmode=verify-full sslrootcert=$(env_value DB_SSLROOTCERT)" \
        -X -q -v ON_ERROR_STOP=1 "$@"
}

step "Database connection (agenda_app, TLS verify-full)"
psql_as agenda_app "$(env_value DB_PASSWORD)" -tAc 'select 1' > /dev/null || fail "the database did not answer; run caderneta-check-db."
if STATUS=$(php artisan migrate:status --pending --no-ansi 2>&1); then
    PENDING=$(grep -c 'Pending' <<< "$STATUS" || true)
else
    # A new database has no migrations table yet: everything is pending.
    PENDING=$(find database/migrations -name '*.php' | wc -l)
fi

if [ "$PENDING" -gt 0 ]; then
    php artisan migrate:status --pending --no-ansi
    [ "$MIGRATE" -eq 1 ] || fail "$PENDING pending migration(s). Take an RDS snapshot, then run again with --migrate."
    echo "Before going on, take a manual RDS snapshot (console: RDS > caderneta-sef-db > Actions > Take snapshot)."
    if [ "$ASSUME_YES" -eq 0 ]; then
        read -rp "Run the $PENDING migration(s) above on the production database? Type MIGRATE: " answer
        [ "$answer" = MIGRATE ] || fail "stopped before migrating; nothing changed."
    fi
    [ -r "$OWNER_FILE" ] || fail "$OWNER_FILE is missing; run caderneta-database-setup."
    OWNER_PASSWORD=$(grep -E '^DB_OWNER_PASSWORD=' "$OWNER_FILE" | cut -d= -f2-)

    if [ -n "$PREVIOUS" ]; then
        (cd "$PREVIOUS" && php artisan down --retry=30 --no-ansi)
        trap 'echo "deploy: stopped with the site in maintenance. Check the error above, restore the snapshot if needed, then: cd $PREVIOUS && php artisan up" >&2' EXIT
    fi

    step "Migrations (agenda_owner)"
    DB_USERNAME=agenda_owner DB_PASSWORD=$OWNER_PASSWORD php artisan migrate --force --no-ansi
    DB_USERNAME=agenda_owner DB_PASSWORD=$OWNER_PASSWORD php artisan db:seed --class=ServiceCatalogSeeder --force --no-ansi
    psql_as agenda_owner "$OWNER_PASSWORD" -f database/sql/privileges.sql
    unset OWNER_PASSWORD
fi

step "Checking grants, triggers and constraints (read-only)"
psql_as agenda_app "$(env_value DB_PASSWORD)" -f database/sql/verify.sql

step "Caches"
php artisan config:cache --no-ansi
php artisan route:cache --no-ansi
php artisan view:cache --no-ansi
php artisan event:cache --no-ansi

switch_to() {
    ln -sfn "$1" "$APP_DIR/current.next"
    mv -Tf "$APP_DIR/current.next" "$APP_DIR/current"
    sudo /usr/bin/systemctl reload "php$PHP_VERSION-fpm"
    sudo /usr/bin/systemctl restart caderneta-queue.service
    sudo /usr/bin/systemctl start caderneta-scheduler.timer
}

healthy() {
    for attempt in 1 2 3 4 5; do
        curl -fsS --max-time 10 --resolve "$HOST:443:127.0.0.1" "https://$HOST/up" > /dev/null && return 0
        sleep 2
    done
    return 1
}

step "Switching to $(basename "$RELEASE")"
switch_to "$RELEASE"
php artisan up --no-ansi > /dev/null
trap - EXIT

if ! healthy; then
    [ -n "$PREVIOUS" ] || fail "the new release does not answer on https://$HOST/up and there is no previous one."
    echo "The new release does not answer on https://$HOST/up; going back to $(basename "$PREVIOUS")." >&2
    switch_to "$PREVIOUS"
    fail "rolled back. The database was not rolled back; see docs/operations.md."
fi

step "Removing old releases (keeping $KEEP_RELEASES)"
find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d | sort -r | tail -n +$((KEEP_RELEASES + 1)) | while read -r old; do
    [ "$old" = "$PREVIOUS" ] || rm -rf -- "$old"
done

echo "Deployed $(git -C "$RELEASE" rev-parse --short HEAD) to https://$HOST"
