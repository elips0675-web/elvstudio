<?php

use Elv\Kit\Classes\Http\AccountController;
use Elv\Kit\Classes\Http\BookingController;
use Elv\Kit\Classes\Http\ChatController;
use Elv\Kit\Classes\Http\DownloadController;
use Elv\Kit\Classes\Http\FeedbackController;
use Elv\Kit\Classes\Http\LicenseController;
use Elv\Kit\Classes\Http\OrderController;
use Elv\Kit\Classes\Http\PaymentController;
use Elv\Kit\Classes\Http\QuoteController;
use Elv\Kit\Classes\Http\QuestionController;
use Elv\Kit\Classes\Http\ReviewController;
use Elv\Kit\Classes\Http\SeoController;
use Elv\Kit\Classes\Http\SubscribeController;
use Elv\Kit\Classes\Http\TelegramController;
use Elv\Kit\Classes\Http\TicketController;
use Illuminate\Support\Facades\Route;

/**
 * Маршруты Elv Kit. Формы живут под `web` (сессия + CSRF), вебхуки — без
 * сессии: подпись шлюза и есть аутентификация, а CSRF-токена у Stripe нет.
 *
 * `/template` — снятый с продажи второй продукт. Слэш на конце October
 * добавляет общим обработчиком, поэтому оба адреса ведут сразу на `/kit/`.
 */
Route::get('template', function () {
    return redirect('/kit/', 301);
});
Route::get('template/', function () {
    return redirect('/kit/', 301);
});

Route::middleware(['web', 'throttle:20,1'])->group(function () {
    Route::post('order', [OrderController::class, 'store'])->name('elv.kit.order');
    Route::post('booking', [BookingController::class, 'store'])->name('elv.kit.booking');
    Route::post('feedback', [FeedbackController::class, 'store'])->name('elv.kit.feedback');
    Route::post('subscribe', [SubscribeController::class, 'store'])->name('elv.kit.subscribe');
});

Route::middleware(['web', 'throttle:40,1'])->group(function () {
    Route::post('chat', [ChatController::class, 'store'])->name('elv.kit.chat');
});

Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('chat/handoff', [ChatController::class, 'handoff'])->name('elv.kit.chat.handoff');
});

Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('account/login', [AccountController::class, 'login'])->name('elv.kit.account.login');
    Route::get('account/auth/{token}', [AccountController::class, 'auth'])
        ->where('token', '[a-f0-9]{48}');
    Route::post('account/logout', [AccountController::class, 'logout'])->name('elv.kit.account.logout');
    Route::post('account/domain/{id}/unbind', [AccountController::class, 'unbind'])
        ->where('id', '[0-9]+')
        ->name('elv.kit.account.unbind');

    Route::post('tickets', [TicketController::class, 'store'])->name('elv.kit.ticket.store');
    Route::get('tickets/{id}/thread', [TicketController::class, 'thread'])
        ->where('id', '[0-9]+')
        ->name('elv.kit.ticket.thread');
    Route::get('tickets/{id}/file/{name}', [TicketController::class, 'file'])
        ->where('id', '[0-9]+')
        ->where('name', '[A-Za-z0-9]{32}\.[a-z0-9]{2,5}')
        ->name('elv.kit.ticket.file');
    Route::post('tickets/{id}/reply', [TicketController::class, 'reply'])
        ->where('id', '[0-9]+')
        ->name('elv.kit.ticket.reply');
    Route::post('tickets/{id}/close', [TicketController::class, 'close'])
        ->where('id', '[0-9]+')
        ->name('elv.kit.ticket.close');
});

Route::middleware(['web', 'throttle:10,1'])->group(function () {
    Route::post('questions', [QuestionController::class, 'store'])->name('elv.kit.question.store');
    Route::post('review/{token}', [ReviewController::class, 'store'])
        ->where('token', '[a-f0-9]{48}')
        ->name('elv.kit.review.store');
});

Route::middleware('throttle:60,1')->group(function () {
    Route::post('telegram/webhook/{secret}', [TelegramController::class, 'webhook'])
        ->where('secret', '[A-Za-z0-9_-]{16,64}')
        ->name('elv.kit.telegram.webhook');
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
