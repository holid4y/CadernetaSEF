#!/usr/bin/env bash
# Points the application at an SMTP account and sends a test message. With
# Gmail, use an app password (Google Account > Security > 2-Step Verification
# > App passwords). The password is typed without echo and only written to
# the protected .env. Reminders to clients keep their own switch
# (MAIL_REMINDERS_ENABLED); this only makes e-mail leave the server.
#
#   sudo caderneta-mail-setup

set -euo pipefail

APP_DIR=/var/www/caderneta-sef
DEPLOY_USER=caderneta
ENV_FILE=$APP_DIR/shared/.env

fail() { echo "mail-setup: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "run it with sudo."
[ -f "$ENV_FILE" ] || fail "$ENV_FILE is missing: run provision.sh first."
[ -L "$APP_DIR/current" ] || fail "deploy the application first."

EMAIL='^[^@[:space:]]+@[^@[:space:]]+\.[A-Za-z]{2,}$'

# Asks again until the answer is usable, so a typo does not end the setup.
ask_address() {
    local prompt=$1 answer
    while true; do
        read -rp "$prompt" answer
        [[ $answer =~ $EMAIL ]] && { printf '%s' "$answer"; return; }
        echo "  Type a full e-mail address, like projeto@gmail.com." >&2
    done
}

ADDRESS=$(ask_address "Gmail (or SMTP account) that sends the messages: ")
read -rp "SMTP host [smtp.gmail.com]: " HOST
HOST=${HOST:-smtp.gmail.com}
read -rp "SMTP port [587]: " PORT
PORT=${PORT:-587}
while true; do
    read -rsp "App password (16 letters from Google; nothing shows while typing): " PASSWORD
    echo
    PASSWORD=${PASSWORD// /}
    [ -z "$PASSWORD" ] && { echo "  The password is empty." >&2; continue; }
    [[ $PASSWORD == *"'"* ]] && { echo "  The password can not contain a single quote." >&2; continue; }
    break
done
TEST_TO=$(ask_address "Send a test message to: ")

# Rewrites the keys in place; values are single-quoted, so no character in the
# password is interpreted by the .env parser.
MAIL_ADDRESS=$ADDRESS MAIL_HOST_VALUE=$HOST MAIL_PORT_VALUE=$PORT MAIL_SECRET=$PASSWORD python3 -I - "$ENV_FILE" <<'PY'
import os, re, sys
path = sys.argv[1]
values = {
    'MAIL_MAILER': 'smtp',
    'MAIL_SCHEME': 'null',
    'MAIL_HOST': os.environ['MAIL_HOST_VALUE'],
    'MAIL_PORT': os.environ['MAIL_PORT_VALUE'],
    'MAIL_USERNAME': os.environ['MAIL_ADDRESS'],
    'MAIL_PASSWORD': "'" + os.environ['MAIL_SECRET'] + "'",
    'MAIL_FROM_ADDRESS': '"' + os.environ['MAIL_ADDRESS'] + '"',
}
lines = open(path).read().splitlines()
seen = set()
for index, line in enumerate(lines):
    key = line.split('=', 1)[0]
    if key in values:
        lines[index] = f'{key}={values[key]}'
        seen.add(key)
lines += [f'{key}={value}' for key, value in values.items() if key not in seen]
open(path, 'w').write('\n'.join(lines) + '\n')
PY
chown "$DEPLOY_USER":www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

cd "$APP_DIR/current"
sudo -u "$DEPLOY_USER" php artisan config:cache --no-ansi > /dev/null
systemctl reload "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm"
systemctl restart caderneta-queue.service

RESULT=$(sudo -u "$DEPLOY_USER" env TEST_TO="$TEST_TO" php artisan tinker --execute='
Illuminate\Support\Facades\Mail::raw("Este e-mail confirma que a CadernetaSEF consegue enviar mensagens.", function ($message) {
    $message->to(getenv("TEST_TO"))->subject("Teste de envio da CadernetaSEF");
});
echo "Test message accepted by the mail server.", PHP_EOL;' 2>&1 || true)
grep -q 'Test message accepted' <<< "$RESULT" \
    || fail "the mail server refused the message (check the address and the app password): $(tail -n 3 <<< "$RESULT")"
echo "Test message accepted by the mail server; check the inbox of $TEST_TO (and the spam folder)."

echo "Done. Password reset e-mails now leave the server. Client reminders: MAIL_REMINDERS_ENABLED in $ENV_FILE."
