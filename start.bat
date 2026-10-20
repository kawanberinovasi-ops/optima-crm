@echo off
REM Jalankan OPTIMA CRM versi uji di http://localhost:5173
cd /d "%~dp0"
set PORT=5173
echo OPTIMA CRM jalan di http://localhost:%PORT%  (Ctrl+C buat berhenti)
start "" http://localhost:%PORT%
where python >nul 2>nul && (python -m http.server %PORT% & goto :eof)
where npx >nul 2>nul && (npx --yes serve -l %PORT% . & goto :eof)
echo Butuh Python 3 atau Node.js. Install salah satu dulu ya.
pause
