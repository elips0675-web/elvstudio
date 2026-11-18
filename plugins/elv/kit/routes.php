<?php

use Elv\Kit\Classes\Http\BookingController;
use Elv\Kit\Classes\Http\ChatController;
use Elv\Kit\Classes\Http\DownloadController;
use Elv\Kit\Classes\Http\FeedbackController;
use Elv\Kit\Classes\Http\LicenseController;
use Elv\Kit\Classes\Http\OrderController;
use Elv\Kit\Classes\Http\PaymentController;
use Elv\Kit\Classes\Http\QuoteController;
use Elv\Kit\Classes\Http\SeoController;
use Elv\Kit\Classes\Http\SubscribeController;
use Illuminate\Support\Facades\Route;

/**
 * Маршруты Elv Kit. Формы живут под `web` (сессия + CSRF), вебхуки — без
 * сессии: подпись шлюза и есть аутентификация, а CSRF-токена у Stripe нет.
 */
Route::middleware(['web', 'throttle:20,1'])->group(function () {
    Route::post('order', [OrderController::class, 'store'])->name('elv.kit.order');
    Route::post('booking', [BookingController::class, 'store'])->name('elv.kit.booking');
    Route::post('feedback', [FeedbackController::class, 'store'])->name('elv.kit.feedback');
    Route::post('subscribe', [SubscribeController::class, 'store'])->name('elv.kit.subscribe');
});

Route::middleware(['web', 'throttle:40,1'])->group(function () {
    Route::post('chat', [ChatController::class, 'store'])->name('elv.kit.chat');
});

Route::middleware(['web', 'throttle:60,1'])->group(function () {
    Route::get('subscribe/confirm/{token}', [SubscribeController::class, 'confirm'])
        ->where('token', '[a-f0-9]{48}');
    Route::get('unsubscribe/{token}', [SubscribeController::class, 'unsubscribe'])
        ->where('token', '[a-f0-9]{48}');
    Route::get('download/{token}', [DownloadController::class, 'show'])
        ->where('token', '[a-f0-9]{48}');
});

Route::middleware('throttle:30,1')->group(function () {
    Route::post('payment/yookassa', [PaymentController::class, 'yookassa']);
    Route::post('payment/stripe', [PaymentController::class, 'stripe']);
});

Route::middleware(['web', 'throttle:20,1'])->group(function () {
    Route::get('calculator/quote.pdf', [QuoteController::class, 'pdf'])->name('elv.kit.calculator.pdf');
});

Route::middleware('throttle:120,1')->group(function () {
    Route::get('sitemap.xml', [SeoController::class, 'sitemap']);
    Route::get('robots.txt', [SeoController::class, 'robots']);
    Route::get('llms.txt', [SeoController::class, 'llms']);
    Route::get('rss.xml', [SeoController::class, 'rss']);
    Route::get('search-index.json', [SeoController::class, 'searchIndex']);
});

Route::prefix('api/v1')->middleware('throttle:60,1')->group(function () {
    Route::post('license/activate', [LicenseController::class, 'activate']);
    Route::get('license/stats', [LicenseController::class, 'stats']);
});
