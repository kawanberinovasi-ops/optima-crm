#!/usr/bin/env bash
# Jalankan OPTIMA CRM lengkap (backend Laravel + PostgreSQL) di http://127.0.0.1:8000 lalu buka browser.
#   ./start-backend.sh            nyalakan dan buka browser
#   ./start-backend.sh --reset    isi ulang database dengan data contoh dulu
# Ctrl+C menghentikan server web; PostgreSQL dibiarkan jalan.
cd "$(dirname "$0")/backend" || exit 1
PORT=${PORT:-8000}
URL="http://127.0.0.1:$PORT"

# PHP: Herd Lite kalau ada, kalau tidak php dari PATH.
PHP=${PHP:-$HOME/.config/herd-lite/bin/php}
[ -x "$PHP" ] || PHP=$(command -v php) || { echo "PHP belum terpasang."; exit 1; }

# PostgreSQL lokal: nyalakan kalau belum jalan.
PG_BIN=${PG_BIN:-$HOME/.local/pgsql-16/bin}
PG_DATA=${PG_DATA:-$HOME/.local/pgsql-16-data}
if [ -x "$PG_BIN/pg_ctl" ] && ! "$PG_BIN/pg_ctl" -D "$PG_DATA" status >/dev/null 2>&1; then
  echo "Menyalakan PostgreSQL..."
  "$PG_BIN/pg_ctl" -D "$PG_DATA" -l "$HOME/.local/pgsql-16.log" start >/dev/null || { echo "PostgreSQL gagal jalan, cek $HOME/.local/pgsql-16.log"; exit 1; }
  sleep 2
fi

"$PHP" artisan migrate --force >/dev/null || { echo "Migrasi database gagal."; exit 1; }
if [ "$1" = "--reset" ]; then
  echo "Mengisi ulang data contoh..."
  "$PHP" artisan migrate:fresh --seed --force >/dev/null || exit 1
fi

open_browser() { (sleep 2; command -v open >/dev/null && open "$URL" || xdg-open "$URL") >/dev/null 2>&1 & }

# Server sudah jalan di port ini: cukup buka browser.
if curl -s -o /dev/null "$URL"; then
  echo "OPTIMA CRM sudah jalan di $URL"
  open_browser
  exit 0
fi

echo "OPTIMA CRM jalan di $URL  (Ctrl+C buat berhenti)"
open_browser
exec "$PHP" artisan serve --host=127.0.0.1 --port="$PORT"
