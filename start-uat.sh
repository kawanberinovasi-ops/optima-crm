#!/bin/sh
# UAT lewat Cloudflare quick tunnel: nyalakan server lokal (kalau belum), buka tunnel, lalu set APP_URL dan
# GOOGLE_REDIRECT_URI ke link baru. Link trycloudflare berganti setiap kali skrip ini dijalankan ulang;
# redirect URI di Google Cloud (client "OPTIMA CRM web") perlu ikut diganti ke <link>/api/auth/google/callback.
cd "$(dirname "$0")" || exit 1
ROOT=$(pwd)
PHP=${PHP:-$HOME/.config/herd-lite/bin/php}
CF=${CLOUDFLARED:-$HOME/.local/bin/cloudflared}
LOG=/tmp/optima-tunnel.log

if ! curl -s -o /dev/null --max-time 3 http://127.0.0.1:8000/up; then
  echo "Menyalakan server lokal..."
  nohup "$ROOT/start-backend.sh" >/tmp/optima-server.log 2>&1 &
  for i in $(seq 1 30); do curl -s -o /dev/null --max-time 2 http://127.0.0.1:8000/up && break; sleep 1; done
fi
curl -s -o /dev/null --max-time 3 http://127.0.0.1:8000/up || { echo "Server lokal gagal jalan, cek /tmp/optima-server.log"; exit 1; }

: > "$LOG"
"$CF" tunnel --no-autoupdate --url http://127.0.0.1:8000 >"$LOG" 2>&1 &
CF_PID=$!
trap 'kill $CF_PID 2>/dev/null' INT TERM EXIT
for i in $(seq 1 40); do
  URL=$(grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' "$LOG" | head -1)
  [ -n "$URL" ] && break
  sleep 1
done
[ -n "$URL" ] || { echo "Tunnel gagal dibuat, cek $LOG"; exit 1; }

sed -i '' "s#^APP_URL=.*#APP_URL=$URL#; s#^GOOGLE_REDIRECT_URI=.*#GOOGLE_REDIRECT_URI=$URL/api/auth/google/callback#" backend/.env
(cd backend && "$PHP" artisan config:clear -q)

echo ""
echo "OPTIMA CRM UAT: $URL"
echo "Redirect Google: $URL/api/auth/google/callback"
echo "Biarkan jendela ini terbuka dan laptop jangan sleep. Ctrl+C untuk berhenti."
wait $CF_PID
