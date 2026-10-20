<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\ClientPartnerController;
use App\Http\Controllers\ContactController;
use Illuminate\Support\Facades\Route;

/*
| Semua route di sini memakai grup "web" (sesi + CSRF) dengan prefix /api. Lihat bootstrap/app.php.
*/

// Login (SRS 4.1)
Route::get('session', [BootstrapController::class, 'session']);
Route::get('auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('auth/google/callback', [AuthController::class, 'googleCallback']);
Route::post('auth/dev-login', [AuthController::class, 'devLogin'])->middleware('throttle:20,1');
Route::post('auth/logout', [AuthController::class, 'logout']);

Route::middleware('crm')->group(function () {
    Route::get('bootstrap', [BootstrapController::class, 'bootstrap']);
    Route::post('ping', [AuthController::class, 'ping']);

    // Client & Partner Database (SRS 4.2)
    Route::get('client-partners', [ClientPartnerController::class, 'index']);
    Route::post('client-partners', [ClientPartnerController::class, 'store']);
    Route::post('client-partners/duplicates', [ClientPartnerController::class, 'duplicates']);
    Route::get('client-partners/{clientPartner}', [ClientPartnerController::class, 'show']);
    Route::patch('client-partners/{clientPartner}', [ClientPartnerController::class, 'update']);
    Route::get('client-partners/{clientPartner}/history', [ClientPartnerController::class, 'history']);
    Route::post('client-partners/{clientPartner}/archive', [ClientPartnerController::class, 'archive']);
    Route::post('client-partners/{clientPartner}/restore', [ClientPartnerController::class, 'restore']);
    Route::post('client-partners/{clientPartner}/convert', [ClientPartnerController::class, 'convert']);
    Route::post('client-partners/{clientPartner}/move-pic', [ClientPartnerController::class, 'movePic']);

    Route::post('client-partners/{clientPartner}/contacts', [ContactController::class, 'store']);
    Route::patch('contacts/{contact}', [ContactController::class, 'update']);
    Route::delete('contacts/{contact}', [ContactController::class, 'destroy']);
    Route::post('contacts/{contact}/primary', [ContactController::class, 'primary']);
});
