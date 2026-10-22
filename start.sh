#!/usr/bin/env bash
# Jalankan OPTIMA CRM Client Preview di http://localhost:5175
cd "$(dirname "$0")"
PORT=${PORT:-5175}
URL="http://127.0.0.1:$PORT"
( sleep 1; command -v open >/dev/null && open "$URL" || command -v xdg-open >/dev/null && xdg-open "$URL" ) >/dev/null 2>&1 &
if command -v python3 >/dev/null; then python3 serve.py "$PORT"
elif command -v npx >/dev/null; then echo "OPTIMA CRM jalan di $URL"; npx --yes serve -l "$PORT" .
else echo "Butuh Python 3 atau Node.js. Install salah satu dulu ya."; exit 1; fi
