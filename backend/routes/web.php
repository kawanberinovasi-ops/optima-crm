<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| Frontend prototipe (index.html + assets/) disajikan dari domain yang sama dengan API.
| Penanda window.OPTIMA_API memberi tahu frontend untuk memakai API, bukan data contoh di browser.
| assets/ disajikan langsung dari public/assets (symlink ke folder assets di root repo).
*/
Route::get('/', function (Request $request) {
    $html = file_get_contents(config('optima.frontend_path').'/index.html');
    // Path relatif (bukan URL lengkap), supaya cookie sesi tetap terkirim walau dibuka lewat localhost atau 127.0.0.1.
    // Skrip inline ini satu-satunya yang diizinkan Content-Security-Policy, lewat nonce acak per permintaan.
    $nonce = base64_encode(random_bytes(18));
    $request->attributes->set('csp_nonce', $nonce);
    $flag = '<script nonce="'.$nonce.'">window.OPTIMA_API = '.json_encode($request->getBaseUrl().'/api').';</script>';
    // Aset tanpa penanda versi (saat pengembangan) diberi ?v=<waktu ubah file>, supaya browser tidak memakai file lama.
    // Paket produksi dari optima:build-frontend sudah membawa ?v=<hash> sendiri, jadi tidak diubah.
    $assets = public_path();
    $html = preg_replace_callback('/(src|href)="(assets\/[^"?]+\.(?:js|css))"/', function ($m) use ($assets) {
        $file = $assets.'/'.$m[2];

        return $m[1].'="'.$m[2].(is_file($file) ? '?v='.filemtime($file) : '').'"';
    }, $html);

    return response(preg_replace('/<script /', $flag."\n<script ", $html, 1))
        ->header('Content-Type', 'text/html; charset=utf-8')
        ->header('Cache-Control', 'no-store');
});
