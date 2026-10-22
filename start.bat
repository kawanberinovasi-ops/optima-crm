@echo off
REM Jalankan OPTIMA CRM Client Preview di http://127.0.0.1:5175
cd /d "%~dp0"
set PORT=5175
echo OPTIMA CRM jalan di http://127.0.0.1:%PORT%  (Ctrl+C buat berhenti)
start "" http://127.0.0.1:%PORT%
where python >nul 2>nul && (python -m http.server %PORT% & goto :eof)
where npx >nul 2>nul && (npx --yes serve -l %PORT% . & goto :eof)
echo Butuh Python 3 atau Node.js. Install salah satu dulu ya.
pause
