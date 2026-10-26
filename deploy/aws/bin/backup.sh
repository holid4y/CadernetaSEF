#!/usr/bin/env bash
# Logical backup of the RDS database to this EC2, on top of the RDS
# automated backups. The dump holds personal data: it stays readable by the
# deploy user only, and the encrypted columns still need the APP_KEY.
#
#   sudo -u caderneta caderneta-backup
#
# Restoring is described in docs/database-production.md; it always goes to a
# new database, never over the one in use.

set -euo pipefail

APP_DIR=/var/www/caderneta-sef
ENV_FILE=$APP_DIR/shared/.env
OWNER_FILE=$APP_DIR/shared/db-owner.env
TARGET=/var/backups/caderneta-sef
KEEP_DAYS=${KEEP_DAYS:-14}

fail() { echo "backup: $*" >&2; exit 1; }
env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }

[ "$(id -un)" = caderneta ] || fail "run it as caderneta: sudo -u caderneta caderneta-backup"
[ -r "$OWNER_FILE" ] || fail "$OWNER_FILE is missing; run caderneta-database-setup."

umask 077
FILE=$TARGET/$(env_value DB_DATABASE)-$(date -u +%Y%m%dT%H%M%SZ).dump
PGPASSWORD=$(grep -E '^DB_OWNER_PASSWORD=' "$OWNER_FILE" | cut -d= -f2-) pg_dump \
    "host=$(env_value DB_HOST) port=$(env_value DB_PORT) dbname=$(env_value DB_DATABASE) user=agenda_owner sslmode=verify-full sslrootcert=$(env_value DB_SSLROOTCERT)" \
    --format=custom --no-owner --no-privileges --file="$FILE.partial"
mv "$FILE.partial" "$FILE"
sha256sum "$FILE" > "$FILE.sha256"

find "$TARGET" -name '*.dump*' -mtime +"$KEEP_DAYS" -delete
echo "Backup written to $FILE ($(du -h "$FILE" | cut -f1)). Older than $KEEP_DAYS days were removed."
