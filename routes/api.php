<?php

use App\Http\Controllers\OdooWebhookController;
use App\Http\Controllers\FakeFocusController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/odoo/webhook', [OdooWebhookController::class, 'receberVenda'])
    ->middleware('webhook.verify');

Route::post('/fake-focus/nfce', [FakeFocusController::class, 'emitir']);

