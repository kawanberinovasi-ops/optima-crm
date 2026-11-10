#!/bin/sh
# Dijalankan setiap container start: cache config dari environment, migrasi, data awal, lalu web server.
set -e
cd /app

# APP_KEY dari platform bisa berupa 32 karakter mentah (tombol Generate Render: 32 hex) yang langsung valid untuk
# AES-256-CBC, atau base64 tanpa awalan (perlu "base64:").
if [ -n "${APP_KEY:-}" ] && [ "${APP_KEY#base64:}" = "$APP_KEY" ] && [ "${#APP_KEY}" -ne 32 ]; then
  export APP_KEY="base64:${APP_KEY}"
fi

php artisan optimize
php artisan migrate --force
php artisan optima:first-run

# FrankenPHP (Caddy) melayani public/ di port dari platform; HTTPS ditangani platform di depannya.
export SERVER_NAME=":${PORT:-8080}"
CADDYFILE=/etc/frankenphp/Caddyfile
[ -f "$CADDYFILE" ] || CADDYFILE=/etc/caddy/Caddyfile
exec frankenphp run --config "$CADDYFILE"
