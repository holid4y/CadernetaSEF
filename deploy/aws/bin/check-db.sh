#!/usr/bin/env bash
# Read-only diagnosis of the path from this EC2 to the RDS database, step by
# step, with what to look at when a step fails. Changes nothing and prints
# no password.
#
#   sudo -u caderneta caderneta-check-db

set -uo pipefail

ENV_FILE=${CADERNETA_ENV_FILE:-/var/www/caderneta-sef/shared/.env}
VERIFY_SQL=${CADERNETA_VERIFY_SQL:-/usr/local/share/caderneta-sef/sql/verify.sql}

env_value() { grep -E "^$1=" "$ENV_FILE" | tail -n 1 | cut -d= -f2- | sed -E 's/^"(.*)"$/\1/'; }
ok() { echo "  ok    $*"; }
bad() { echo "  FAIL  $1"; echo "        $2"; exit 1; }

[ -r "$ENV_FILE" ] || bad "$ENV_FILE can not be read" "Run it as caderneta: sudo -u caderneta caderneta-check-db"
HOST=$(env_value DB_HOST) PORT=$(env_value DB_PORT) DATABASE=$(env_value DB_DATABASE)
USERNAME=$(env_value DB_USERNAME) CA=$(env_value DB_SSLROOTCERT) MODE=$(env_value DB_SSLMODE)
echo "Database $DATABASE on $HOST:$PORT as $USERNAME (sslmode=$MODE)"

[ "$MODE" = verify-full ] || bad "DB_SSLMODE=$MODE" "Use verify-full: the RDS certificate has to be checked."
{ [ -r "$CA" ] && grep -q 'BEGIN CERTIFICATE' "$CA"; } && ok "RDS certificate bundle at $CA" \
    || bad "certificate bundle missing: $CA" "Run provision.sh again, or download https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem"

ADDRESS=$(getent hosts "$HOST" | awk '{ print $1; exit }')
[ -n "$ADDRESS" ] || bad "$HOST does not resolve" "Copy the endpoint again from RDS > caderneta-sef-db > Connectivity & security."
case "$ADDRESS" in
    10.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*|192.168.*|127.*|::1) ok "endpoint resolves to the private address $ADDRESS" ;;
    *) echo "  WARN  $HOST resolves to $ADDRESS, a public address: check that the database is not Publicly accessible." ;;
esac

timeout 5 bash -c "exec 3<>/dev/tcp/$HOST/$PORT" 2>/dev/null && ok "port $PORT reachable" \
    || bad "port $PORT does not answer" "The RDS security group must accept 5432 from this instance's security group (rds-ec2-1 / ec2-rds-1), in the same VPC."

RESULT=$(PGPASSWORD=$(env_value DB_PASSWORD) PGCONNECT_TIMEOUT=10 psql "host=$HOST port=$PORT dbname=$DATABASE user=$USERNAME sslmode=verify-full sslrootcert=$CA" \
    -X -tAc "select current_user || ', ' || version || ', ' || split_part(version(), ' on ', 1) from pg_stat_ssl where pid = pg_backend_pid()" 2>&1) \
    || bad "connection refused: $(echo "$RESULT" | head -n 1)" "Wrong password: run caderneta-database-setup. 'certificate verify failed': endpoint or bundle. 'no pg_hba.conf entry': wrong database or user."
ok "connected with TLS: $RESULT"

PGPASSWORD=$(env_value DB_PASSWORD) psql "host=$HOST port=$PORT dbname=$DATABASE user=$USERNAME sslmode=verify-full sslrootcert=$CA" \
    -X -q -v ON_ERROR_STOP=1 -f "$VERIFY_SQL" 2>&1 | sed 's/^/        /'
[ "${PIPESTATUS[0]}" -eq 0 ] && ok "grants, triggers and constraints as expected" \
    || bad "the database check found problems (above)" "If the migrations never ran, deploy with --migrate first."
