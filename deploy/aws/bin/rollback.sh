#!/usr/bin/env bash
# Points the site back to an earlier release. The code goes back; the
# database does not (see docs/operations.md before undoing a migration).
#
#   sudo -u caderneta caderneta-rollback                  # the release before the current one
#   sudo -u caderneta caderneta-rollback 20261023140501   # a specific release

set -euo pipefail

APP_DIR=/var/www/caderneta-sef

fail() { echo "rollback: $*" >&2; exit 1; }

[ "$(id -un)" = caderneta ] || fail "run it as caderneta: sudo -u caderneta caderneta-rollback"
[ -L "$APP_DIR/current" ] || fail "there is no current release."
CURRENT=$(readlink -f "$APP_DIR/current")

if [ $# -gt 0 ]; then
    TARGET=$APP_DIR/releases/$1
else
    TARGET=$(find "$APP_DIR/releases" -mindepth 1 -maxdepth 1 -type d | sort | awk -v current="$CURRENT" '$0 == current { print previous; exit } { previous = $0 }')
fi

[ -n "$TARGET" ] && [ -d "$TARGET" ] || fail "no release to go back to."
[ "$TARGET" != "$CURRENT" ] || fail "$(basename "$TARGET") is already the current release."
[ -f "$TARGET/bootstrap/cache/config.php" ] || fail "$(basename "$TARGET") was never completed (no cached config)."

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
ln -sfn "$TARGET" "$APP_DIR/current.next"
mv -Tf "$APP_DIR/current.next" "$APP_DIR/current"
sudo /usr/bin/systemctl reload "php$PHP_VERSION-fpm"
sudo /usr/bin/systemctl restart caderneta-queue.service
(cd "$TARGET" && php artisan up --no-ansi > /dev/null)

HOST=$(grep -E '^APP_URL=' "$APP_DIR/shared/.env" | cut -d= -f2- | sed -E 's#^"?https://##; s#[/"].*$##')
curl -fsS --max-time 10 --resolve "$HOST:443:127.0.0.1" "https://$HOST/up" > /dev/null \
    || fail "$(basename "$TARGET") is current but does not answer on https://$HOST/up."

echo "Now serving $(basename "$TARGET") (was $(basename "$CURRENT"))."
