#!/bin/sh
set -eu

mkdir -p /var/www/html/storage /var/www/html/uploads /run/nginx /var/lib/nginx/tmp /var/log/nginx
chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads
chmod -R 775 /var/www/html/storage /var/www/html/uploads

# Start both processes and keep their PIDs for signal forwarding.
php-fpm --nodaemonize &
FPM_PID=$!

nginx -g 'daemon off;' &
NGINX_PID=$!

# Graceful shutdown handler for SIGTERM/SIGINT.
_shutdown() {
    kill -QUIT "$NGINX_PID" 2>/dev/null || true
    kill -TERM "$FPM_PID" 2>/dev/null || true
    wait "$NGINX_PID" "$FPM_PID" 2>/dev/null || true
}
trap '_shutdown; exit 0' TERM INT

# Exit container if either process exits unexpectedly.
while kill -0 "$NGINX_PID" 2>/dev/null && kill -0 "$FPM_PID" 2>/dev/null; do
    sleep 5
done
_shutdown
exit 1
