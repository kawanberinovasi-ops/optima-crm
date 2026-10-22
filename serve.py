# Server lokal OPTIMA CRM tanpa cache, jadi file yang baru diubah langsung kepakai.
import http.server, os, sys
os.chdir(os.path.dirname(os.path.abspath(__file__)))
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 5175
class H(http.server.SimpleHTTPRequestHandler):
    def end_headers(self):
        self.send_header('Cache-Control', 'no-store, must-revalidate')
        super().end_headers()
    def log_message(self, *a):
        pass
print(f'OPTIMA CRM Client Preview jalan di http://127.0.0.1:{PORT}  (Ctrl+C buat berhenti)')
http.server.ThreadingHTTPServer(('127.0.0.1', PORT), H).serve_forever()
