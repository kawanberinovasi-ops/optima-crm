<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\ClientPartnerController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\InteractionController;
use App\Http\Controllers\PipelineController;
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

    // Pipeline dan deal (SRS 4.3)
    Route::post('pipelines', [PipelineController::class, 'store']);
    Route::post('pipelines/{pipeline}/stages', [PipelineController::class, 'storeStage']);
    Route::patch('stages/{stage}', [PipelineController::class, 'updateStage']);
    Route::delete('stages/{stage}', [PipelineController::class, 'destroyStage']);
    Route::get('deals', [DealController::class, 'index']);
    Route::post('deals', [DealController::class, 'store']);
    Route::patch('deals/{deal}', [DealController::class, 'update']);
    Route::post('deals/{deal}/move', [DealController::class, 'move']);
    Route::post('deals/{deal}/won', [DealController::class, 'won']);
    Route::post('deals/{deal}/lost', [DealController::class, 'lost']);
    Route::post('deals/{deal}/hold', [DealController::class, 'hold']);
    Route::post('deals/{deal}/reopen', [DealController::class, 'reopen']);

    // Interaksi dan follow-up (SRS 4.2)
    Route::get('interactions', [InteractionController::class, 'index']);
    Route::post('interactions', [InteractionController::class, 'store']);
    Route::post('interactions/{interaction}/done', [InteractionController::class, 'done']);
    Route::post('interactions/{interaction}/cancel', [InteractionController::class, 'cancel']);
});
