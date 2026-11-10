#!/bin/sh
# Dijalankan setiap container start: cache config dari environment, migrasi, data awal, lalu web server.
set -e
cd /app

# Render "generateValue" memberi base64 tanpa awalan; Laravel butuh "base64:".
case "${APP_KEY:-}" in
  ""|base64:*) ;;
  *) export APP_KEY="base64:${APP_KEY}" ;;
esac

php artisan optimize
php artisan migrate --force
php artisan optima:first-run

# FrankenPHP (Caddy) melayani public/ di port dari platform; HTTPS ditangani platform di depannya.
export SERVER_NAME=":${PORT:-8080}"
CADDYFILE=/etc/frankenphp/Caddyfile
[ -f "$CADDYFILE" ] || CADDYFILE=/etc/caddy/Caddyfile
exec frankenphp run --config "$CADDYFILE"
