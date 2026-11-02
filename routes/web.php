<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/robots.txt', [HomeController::class, 'robots'])->name('robots');
Route::get('/sitemap.xml', [HomeController::class, 'sitemap'])->name('sitemap');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:3,1')
        ->name('password.email');
    Route::get('/reset-password', [PasswordResetController::class, 'showResetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware(['auth', 'active'])->name('dashboard');
Route::post('/telegram/link', [DashboardController::class, 'createTelegramLink'])->middleware(['auth', 'active'])->name('telegram.link');
Route::post('/checkout/{tariff}', [PaymentController::class, 'create'])
    ->middleware(['auth', 'active', 'throttle:10,1'])
    ->name('checkout');
Route::post('/checkout/{tariff}/nowpayments', [PaymentController::class, 'createNowPayments'])
    ->middleware(['auth', 'active', 'throttle:10,1'])
    ->name('checkout.nowpayments');

Route::post('/webhooks/yookassa', [PaymentController::class, 'webhook'])->name('webhooks.yookassa');
Route::post('/webhooks/nowpayments', [PaymentController::class, 'nowPaymentsWebhook'])->name('webhooks.nowpayments');
Route::post('/webhooks/telegram', TelegramWebhookController::class)->name('webhooks.telegram');

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'active', 'admin'])
    ->group(function (): void {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::get('/users', [AdminController::class, 'page'])->defaults('section', 'users')->name('users');
        Route::get('/tariffs', [AdminController::class, 'page'])->defaults('section', 'tariffs')->name('tariffs');
        Route::get('/panels', [AdminController::class, 'page'])->defaults('section', 'panels')->name('panels');
        Route::get('/orders', [AdminController::class, 'page'])->defaults('section', 'orders')->name('orders');
        Route::get('/payment-events', [AdminController::class, 'page'])->defaults('section', 'payment-events')->name('payment-events');
        Route::get('/payment-settings', [AdminController::class, 'page'])->defaults('section', 'payment-settings')->name('payment-settings');
        Route::get('/mail-settings', [AdminController::class, 'page'])->defaults('section', 'mail-settings')->name('mail-settings');
        Route::get('/site', [AdminController::class, 'page'])->defaults('section', 'site')->name('site');
        Route::get('/seo', [AdminController::class, 'page'])->defaults('section', 'seo')->name('seo');
        Route::get('/system', [AdminController::class, 'page'])->defaults('section', 'system')->name('system');
        Route::get('/users/{user}/edit', [AdminController::class, 'editUser'])->name('users.edit');
        Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::put('/home-content', [AdminController::class, 'updateHomeContent'])->name('home-content.update');
        Route::put('/seo', [AdminController::class, 'updateSeoSettings'])->name('seo.update');
        Route::put('/payment-settings', [AdminController::class, 'updatePaymentSettings'])->name('payment-settings.update');
        Route::post('/payment-settings/nowpayments-rub-check', [AdminController::class, 'verifyNowPaymentsRuble'])
            ->middleware('throttle:3,1')
            ->name('payment-settings.nowpayments-rub-check');
        Route::put('/payment-credentials', [AdminController::class, 'updatePaymentCredentials'])->name('payment-credentials.update');
        Route::put('/mail-settings', [AdminController::class, 'updateMailSettings'])->name('mail-settings.update');
        Route::post('/mail-settings/test', [AdminController::class, 'sendTestMail'])
            ->middleware('throttle:3,1')
            ->name('mail-settings.test');
        Route::post('/payments/test', [AdminController::class, 'createTestPayment'])
            ->middleware('throttle:10,1')
            ->name('payments.test');
        Route::post('/tariffs', [AdminController::class, 'storeTariff'])->name('tariffs.store');
        Route::put('/tariffs/{tariff}', [AdminController::class, 'updateTariff'])->name('tariffs.update');
        Route::post('/panels', [AdminController::class, 'storePanel'])->name('panels.store');
        Route::put('/panels/{panel}', [AdminController::class, 'updatePanel'])->name('panels.update');
        Route::post('/panels/{panel}/connection-test', [AdminController::class, 'checkPanelConnection'])->name('panels.connection-test');
        Route::delete('/panels/{panel}', [AdminController::class, 'deletePanel'])->name('panels.delete');
        Route::put('/users/{user}/panel', [AdminController::class, 'assignPanel'])->name('users.panel');
        Route::post('/orders/{order}/retry', [AdminController::class, 'retryProvisioning'])->name('orders.retry');
    });
