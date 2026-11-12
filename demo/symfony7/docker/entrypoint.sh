#!/bin/sh
set -e

# FRANKENPHP_MODE: classic | worker (REQ-DEMO-010). Default: worker.
MODE="${FRANKENPHP_MODE:-worker}"
case "$MODE" in
	classic)
		cp /etc/frankenphp/Caddyfile.dev /etc/frankenphp/Caddyfile
		;;
	worker)
		;;
	*)
		echo "Unknown FRANKENPHP_MODE=$MODE (expected classic|worker)" >&2
		exit 1
		;;
esac
echo "FrankenPHP mode: $MODE"

git config --global --add safe.directory /app 2>/dev/null || true
git config --global --add safe.directory /var/short-link-bundle 2>/dev/null || true

mkdir -p /app/var/cache /app/var/log
chmod -R 777 /app/var 2>/dev/null || true

if [ ! -f /app/.env ]; then
	cp /app/.env.example /app/.env
fi
# A random APP_SECRET per checkout: nothing secret-like is committed.
if ! grep -q '^APP_SECRET=..*' /app/.env; then
	GENERATED="$(php -r 'echo bin2hex(random_bytes(16));')"
	if grep -q '^APP_SECRET=' /app/.env; then
		sed -i "s/^APP_SECRET=.*/APP_SECRET=${GENERATED}/" /app/.env
	else
		echo "APP_SECRET=${GENERATED}" >> /app/.env
	fi
fi

if [ ! -f /app/vendor/autoload_runtime.php ]; then
	echo "vendor not found, running composer install..."
	if ! composer install --no-interaction --working-dir=/app; then
		echo "composer install failed; the container stays up for debugging."
		exec tail -f /dev/null
	fi
fi

php /app/bin/console assets:install public --no-interaction >/dev/null 2>&1 || true
php /app/bin/console doctrine:database:create --if-not-exists --no-interaction 2>/dev/null || true

exec frankenphp run --config /etc/frankenphp/Caddyfile --adapter caddyfile
