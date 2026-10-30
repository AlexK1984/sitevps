<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth')->name('dashboard');
Route::post('/telegram/link', [DashboardController::class, 'createTelegramLink'])->middleware('auth')->name('telegram.link');
Route::post('/checkout/{tariff}', [PaymentController::class, 'create'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('checkout');

Route::post('/webhooks/yookassa', [PaymentController::class, 'webhook'])->name('webhooks.yookassa');
Route::post('/webhooks/telegram', TelegramWebhookController::class)->name('webhooks.telegram');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'admin'])
    ->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::put('/payment-settings', [AdminController::class, 'updatePaymentSettings'])->name('payment-settings.update');
        Route::put('/payment-credentials', [AdminController::class, 'updatePaymentCredentials'])->name('payment-credentials.update');
        Route::post('/tariffs', [AdminController::class, 'storeTariff'])->name('tariffs.store');
        Route::put('/tariffs/{tariff}', [AdminController::class, 'updateTariff'])->name('tariffs.update');
        Route::post('/panels', [AdminController::class, 'storePanel'])->name('panels.store');
        Route::put('/panels/{panel}', [AdminController::class, 'updatePanel'])->name('panels.update');
        Route::delete('/panels/{panel}', [AdminController::class, 'deletePanel'])->name('panels.delete');
        Route::put('/users/{user}/panel', [AdminController::class, 'assignPanel'])->name('users.panel');
        Route::post('/orders/{order}/retry', [AdminController::class, 'retryProvisioning'])->name('orders.retry');
    });
