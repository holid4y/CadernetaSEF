#!/usr/bin/env bash
# Creates (or rotates the passwords of) agenda_owner, agenda_app and
# agenda_reports on the RDS database, hands the database to agenda_owner and
# points the application at agenda_app. Runs on the EC2, the only machine
# that reaches the private database. Asks for the master password without
# echo; the three new passwords are generated here and never printed.
#
#   sudo caderneta-database-setup --host caderneta-sef-db.xxxx.sa-east-1.rds.amazonaws.com
#
# It changes roles and the database owner, not data. Running it again
# rotates the three passwords and updates the files below.

set -euo pipefail

APP_DIR=/var/www/caderneta-sef
DEPLOY_USER=caderneta
ENV_FILE=$APP_DIR/shared/.env
OWNER_FILE=$APP_DIR/shared/db-owner.env
REPORTS_FILE=$APP_DIR/shared/db-reports.env
CA=/etc/ssl/certs/rds-global-bundle.pem
SQL_DIR=/usr/local/share/caderneta-sef/sql
HOST='' DATABASE=caderneta_sef MASTER=postgres PORT=5432

fail() { echo "database-setup: $*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --host) HOST=$2; shift 2 ;;
        --database) DATABASE=$2; shift 2 ;;
        --master) MASTER=$2; shift 2 ;;
        --port) PORT=$2; shift 2 ;;
        *) fail "unknown option $1" ;;
    esac
done

[ "$(id -u)" -eq 0 ] || fail "run it with sudo."
[ -n "$HOST" ] || fail "pass the RDS endpoint with --host."
[ -f "$ENV_FILE" ] || fail "$ENV_FILE is missing: run provision.sh first."
[ -r "$CA" ] || fail "$CA is missing: run provision.sh first."
[ -f "$SQL_DIR/roles.sql" ] || fail "$SQL_DIR/roles.sql is missing: run provision.sh first."
RELEASE=''
[ ! -L "$APP_DIR/current" ] || RELEASE=$(readlink -f "$APP_DIR/current")

if [ -f "$OWNER_FILE" ]; then
    read -rp "Roles were set up before. Rotate the three passwords now? Type ROTATE: " answer
    [ "$answer" = ROTATE ] || fail "nothing changed."
fi

read -rsp "Password of the RDS master user ($MASTER): " MASTER_PASSWORD
echo
# Every stage reads its whole input, so pipefail never sees a SIGPIPE.
generate() { head -c 96 /dev/urandom | base64 -w 0 | tr -dc 'A-Za-z0-9' | cut -c 1-32; }
AGENDA_OWNER_PASSWORD=$(generate)
AGENDA_APP_PASSWORD=$(generate)
AGENDA_REPORTS_PASSWORD=$(generate)
export AGENDA_OWNER_PASSWORD AGENDA_APP_PASSWORD AGENDA_REPORTS_PASSWORD

CONNECTION="host=$HOST port=$PORT dbname=$DATABASE sslmode=verify-full sslrootcert=$CA"
PGPASSWORD=$MASTER_PASSWORD psql "$CONNECTION user=$MASTER" -X -q -v ON_ERROR_STOP=1 -f "$SQL_DIR/roles.sql"
unset MASTER_PASSWORD

umask 077
printf 'DB_OWNER_PASSWORD=%s\n' "$AGENDA_OWNER_PASSWORD" > "$OWNER_FILE"
printf 'DB_REPORTS_PASSWORD=%s\n' "$AGENDA_REPORTS_PASSWORD" > "$REPORTS_FILE"
chown "$DEPLOY_USER":"$DEPLOY_USER" "$OWNER_FILE"
chown root:root "$REPORTS_FILE"
chmod 600 "$OWNER_FILE" "$REPORTS_FILE"

set_env() {
    if grep -q "^$1=" "$ENV_FILE"; then
        sed -i "s|^$1=.*|$1=$2|" "$ENV_FILE"
    else
        printf '%s=%s\n' "$1" "$2" >> "$ENV_FILE"
    fi
}
set_env DB_HOST "$HOST"
set_env DB_PORT "$PORT"
set_env DB_DATABASE "$DATABASE"
set_env DB_USERNAME agenda_app
set_env DB_PASSWORD "$AGENDA_APP_PASSWORD"
set_env DB_SSLMODE verify-full
set_env DB_SSLROOTCERT "$CA"
chown "$DEPLOY_USER":www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

PGPASSWORD=$AGENDA_APP_PASSWORD psql "$CONNECTION user=agenda_app" -X -tAc "select 'agenda_app connected over ' || version from pg_stat_ssl where pid = pg_backend_pid()"

if [ -n "$RELEASE" ]; then
    sudo -u "$DEPLOY_USER" php "$RELEASE/artisan" config:cache >/dev/null
    systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
    systemctl restart caderneta-queue.service
fi

echo "Roles ready. The owner password is in $OWNER_FILE (deploys use it for migrations)."
echo "Next: sudo -u $DEPLOY_USER caderneta-deploy --ref main --migrate"
