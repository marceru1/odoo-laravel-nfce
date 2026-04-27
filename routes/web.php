<?php
use App\Http\Controllers\PainelFiscalController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/painel/fiscal');
});

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/painel/fiscal', [PainelFiscalController::class, 'index'])->name('painel.index');
    Route::post('/painel/fiscal/cancelar', [PainelFiscalController::class, 'cancelarNota'])->name('painel.cancelar');
});
