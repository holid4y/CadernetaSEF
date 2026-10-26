#!/usr/bin/env bash
# Prepares the EC2 instance (Ubuntu 24.04 or newer) to run the system.
# Installs packages from Ubuntu only, creates the deploy user and folders,
# configures PHP-FPM, Nginx with Let's Encrypt, the queue worker and the
# scheduler. It creates nothing in AWS and can run again at any time.
#
#   sudo deploy/aws/bin/provision.sh agenda.example.org
#
# The domain must already point to this instance (port 80 open to the
# internet for the certificate challenge). Let's Encrypt no longer sends
# expiry e-mails, so registering an address is optional (second argument).

set -euo pipefail

DOMAIN=${1:?"Usage: provision.sh <domain> [e-mail for Let's Encrypt]"}
CERT_EMAIL=${2:-}
APP_DIR=/var/www/caderneta-sef
DEPLOY_USER=caderneta
SOURCE=$(cd "$(dirname "$0")/.." && pwd)

fail() { echo "provision: $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || fail "run it with sudo."
. /etc/os-release
[ "$ID" = ubuntu ] || fail "Ubuntu expected, found $ID."
[[ $DOMAIN =~ ^[a-z0-9.-]+\.[a-z]{2,}$ ]] || fail "invalid domain: $DOMAIN"

echo "==> Packages"
apt-get update -q
DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a apt-get install -y -q \
    nginx php-fpm php-cli php-pgsql php-mbstring php-xml php-curl php-intl php-bcmath php-zip php-gd \
    composer nodejs npm postgresql-client git unzip acl curl certbot unattended-upgrades

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || fail "PHP 8.3+ required, found $PHP_VERSION."
node -e 'const [major, minor] = process.versions.node.split(".").map(Number); process.exit(major > 20 || (major === 20 && minor >= 19) ? 0 : 1)' \
    || fail "Node 20.19+ required by Vite 8, found $(node -v). Install Node 22 from https://github.com/nodesource/distributions and run again."
psql --version | grep -qE ' (1[5-9]|[2-9][0-9])(\.|$)' || fail "psql 15+ required (the SQL scripts use \\getenv)."
echo "PHP $PHP_VERSION, Node $(node -v), $(psql --version)"

echo "==> Swap (Composer and Vite need more than 2 GiB at build time)"
if ! swapon --show=NAME --noheadings | grep -qx /swapfile; then
    [ -f /swapfile ] || { fallocate -l 2G /swapfile; chmod 600 /swapfile; mkswap /swapfile >/dev/null; }
    swapon /swapfile
fi
grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab

echo "==> Deploy user and folders"
id "$DEPLOY_USER" >/dev/null 2>&1 || adduser --system --group --home "/home/$DEPLOY_USER" --shell /bin/bash "$DEPLOY_USER"
usermod -aG www-data "$DEPLOY_USER"
install -d -o "$DEPLOY_USER" -g www-data -m 2750 "$APP_DIR" "$APP_DIR/releases" "$APP_DIR/shared"
for dir in app/private app/public framework/cache/data framework/sessions framework/views framework/testing logs; do
    install -d -o www-data -g www-data -m 2770 "$APP_DIR/shared/storage/$dir"
done
# Files created by artisan during a deploy stay writable for PHP-FPM and the other way round.
setfacl -R -m u:www-data:rwX -m u:"$DEPLOY_USER":rwX -m d:u:www-data:rwX -m d:u:"$DEPLOY_USER":rwX "$APP_DIR/shared/storage"
install -d -o "$DEPLOY_USER" -g "$DEPLOY_USER" -m 700 /var/backups/caderneta-sef

echo "==> Environment file"
ENV_FILE="$APP_DIR/shared/.env"
if [ ! -f "$ENV_FILE" ]; then
    install -o "$DEPLOY_USER" -g www-data -m 640 "$SOURCE/env.production.example" "$ENV_FILE"
    KEY="base64:$(head -c 32 /dev/urandom | base64)"
    sed -i -e "s|^APP_KEY=.*|APP_KEY=$KEY|" -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
        -e "s|^MAIL_FROM_ADDRESS=.*|MAIL_FROM_ADDRESS=\"nao-responda@$DOMAIN\"|" "$ENV_FILE"
    echo "Created $ENV_FILE with a new APP_KEY. Save that key in the project's password manager now."
fi
chown "$DEPLOY_USER":www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"

echo "==> Amazon RDS certificate bundle"
curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem -o /etc/ssl/certs/rds-global-bundle.pem.new
grep -q 'BEGIN CERTIFICATE' /etc/ssl/certs/rds-global-bundle.pem.new || fail "the downloaded RDS bundle is not a certificate file."
mv /etc/ssl/certs/rds-global-bundle.pem.new /etc/ssl/certs/rds-global-bundle.pem
chmod 644 /etc/ssl/certs/rds-global-bundle.pem

echo "==> PHP $PHP_VERSION"
PHP_SOCKET=/run/php/caderneta-sef.sock
install -m 644 "$SOURCE/php/pool.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/caderneta-sef.conf"
install -m 644 "$SOURCE/php/caderneta-sef.ini" "/etc/php/$PHP_VERSION/fpm/conf.d/90-caderneta-sef.ini"
install -m 644 "$SOURCE/php/caderneta-sef.ini" "/etc/php/$PHP_VERSION/cli/conf.d/90-caderneta-sef.ini"
[ ! -f "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" ] || mv "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf" "/etc/php/$PHP_VERSION/fpm/pool.d/www.conf.disabled"
"php-fpm$PHP_VERSION" -t
systemctl enable --now "php$PHP_VERSION-fpm"
systemctl restart "php$PHP_VERSION-fpm"

echo "==> Nginx and HTTPS"
install -d -m 755 /var/www/letsencrypt
rm -f /etc/nginx/sites-enabled/default
SITE=/etc/nginx/sites-available/caderneta-sef.conf
if [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
    # The certificate is only issued when the domain already points here.
    TOKEN=$(curl -fsS -X PUT http://169.254.169.254/latest/api/token -H 'X-aws-ec2-metadata-token-ttl-seconds: 60')
    PUBLIC_IP=$(curl -fsS -H "X-aws-ec2-metadata-token: $TOKEN" http://169.254.169.254/latest/meta-data/public-ipv4)
    RESOLVED=$(getent ahostsv4 "$DOMAIN" | awk '{ print $1; exit }')
    [ "$RESOLVED" = "$PUBLIC_IP" ] || fail "$DOMAIN points to ${RESOLVED:-nothing}, but this instance is $PUBLIC_IP. Fix the DNS record and run again."
    printf 'server {\n    listen 80;\n    listen [::]:80;\n    server_name %s;\n    location /.well-known/acme-challenge/ { root /var/www/letsencrypt; }\n    location / { return 404; }\n}\n' "$DOMAIN" > "$SITE"
    ln -sfn "$SITE" /etc/nginx/sites-enabled/caderneta-sef.conf
    nginx -t && systemctl reload nginx
    if [ -n "$CERT_EMAIL" ]; then
        REGISTRATION=(-m "$CERT_EMAIL" --no-eff-email)
    else
        REGISTRATION=(--register-unsafely-without-email)
    fi
    certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" "${REGISTRATION[@]}" --agree-tos --non-interactive
fi
sed -e "s|__DOMAIN__|$DOMAIN|g" -e "s|__PHP_SOCKET__|$PHP_SOCKET|g" "$SOURCE/nginx/caderneta-sef.conf" > "$SITE"
ln -sfn "$SITE" /etc/nginx/sites-enabled/caderneta-sef.conf
install -d /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
chmod 755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
nginx -t
systemctl enable nginx
systemctl reload nginx

echo "==> Queue worker and scheduler"
install -m 644 "$SOURCE"/systemd/caderneta-*.service "$SOURCE"/systemd/caderneta-*.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable caderneta-queue.service caderneta-scheduler.timer >/dev/null

echo "==> Commands for the deploy user"
install -d -m 755 /usr/local/share/caderneta-sef/sql
install -m 644 "$SOURCE"/../../database/sql/*.sql /usr/local/share/caderneta-sef/sql/
for script in deploy rollback check-db backup database-setup mail-setup; do
    install -m 755 "$SOURCE/bin/$script.sh" "/usr/local/bin/caderneta-$script"
done
SUDOERS=/etc/sudoers.d/caderneta-deploy
cat > "$SUDOERS.new" <<SUDO
$DEPLOY_USER ALL=(root) NOPASSWD: /usr/bin/systemctl reload php$PHP_VERSION-fpm, /usr/bin/systemctl restart caderneta-queue.service, /usr/bin/systemctl start caderneta-scheduler.timer
SUDO
chmod 440 "$SUDOERS.new"
visudo -cf "$SUDOERS.new" >/dev/null
mv "$SUDOERS.new" "$SUDOERS"

echo
echo "Done. Next steps (docs/deployment-aws.md):"
echo "  1. sudo caderneta-database-setup --host <RDS endpoint>"
echo "  2. sudo -u $DEPLOY_USER caderneta-deploy --ref main --migrate"
echo "  3. sudo -u $DEPLOY_USER php $APP_DIR/current/artisan users:create <e-mail> \"<name>\" --role=admin"
