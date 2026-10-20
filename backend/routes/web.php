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
    $flag = '<script>window.OPTIMA_API = '.json_encode($request->getBaseUrl().'/api').';</script>';

    return response(preg_replace('/<script /', $flag."\n<script ", $html, 1))
        ->header('Content-Type', 'text/html; charset=utf-8')
        ->header('Cache-Control', 'no-store');
});
