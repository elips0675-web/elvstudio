<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Community;
use Elv\Kit\Classes\Questions;
use Elv\Kit\Classes\Reviews;
use Elv\Kit\Classes\Seo;
use Elv\Kit\Classes\SecurityHeaders;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Question;
use Elv\Kit\Models\Review;
use Elv\Kit\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;

/*
 * community-check — вопросы, отзывы, премодерация и микроразметка.
 *
 * Проверка не ходит в сеть: почта уходит в array-транспорт, Telegram выключен.
 * Всё, что создаётся, удаляется в конце.
 */

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

config(['mail.default' => 'array']);

// Имя на витрине — «Имя Ф.», e-mail не публикуется.
$check(Community::displayName('Алексей Кузнецов') === 'Алексей К.', 'display name is not «Имя Ф.»');
$check(Community::displayName('  мария   сергеевна  иванова ') === 'мария И.', 'display name does not collapse spaces');
$check(Community::displayName('Пётр') === 'Пётр', 'single name is not kept');
$check(Community::displayName('') === 'Покупатель', 'empty name is not replaced');
$check(Community::average([5, 4]) === 4.5, 'average is not 4.5');
$check(Community::average([]) === 0.0, 'empty average is not zero');

$suffix = bin2hex(random_bytes(4));
$email = 'qa-community-' . $suffix . '@example.test';
$slug = 'qa-product-' . $suffix;

$product = new Product();
$product->name = 'Тестовый продукт ' . $suffix;
$product->slug = $slug;
$product->kind = 'module';
$product->tagline = 'Продукт для проверки вопросов и отзывов';
$product->description = 'Создаётся проверкой community-check и удаляется после неё.';
$product->price_cents = 1000;
$product->is_active = true;
$product->save();

$questions = new Questions();

$first = $questions->ask($product, [
    'name' => 'Алексей Кузнецов',
    'email' => $email,
    'question' => 'Можно ли поставить раздел на два сайта?',
], '127.0.0.1');

$check($first instanceof Question, 'question is not created');
$check((string) $first->status === Question::STATUS_NEW, 'new question is not in «new» status');
$check($first->published_at === null, 'new question already has a publish date');
$check($first->display_name === 'Алексей К.', 'question display name is not «Имя Ф.»');
$check($first->ip_hash !== null && !str_contains((string) $first->ip_hash, '127.0.0.1'), 'question stores a raw IP');
$check(Questions::count($product) === 0, 'unanswered question is already counted');
$check(Questions::published($product)->count() === 0, 'unanswered question is already published');

$second = $questions->ask($product, [
    'name' => 'Мария Иванова',
    'email' => $email,
    'question' => 'Как перенести данные из старой базы?',
], '127.0.0.1');

$questions->answer($first, 'Да, на два сайта нужен тариф «До 5 сайтов».', 'Поддержка');
$first = $first->fresh();

$check((string) $first->status === Question::STATUS_PUBLISHED, 'answer does not publish the question');
$check($first->published_at !== null, 'published question has no publish date');
$check($first->answered_at !== null, 'published question has no answer date');
$check($first->is_published === true, 'published question is not reported as published');
$check(Questions::count($product) === 1, 'published question is not counted');
$check(Questions::published($product)->count() === 1, 'published question is not listed');
$check(Questions::published($product, 1)->count() === 1, 'question limit does not work');

$questions->answer($second, '', 'Поддержка');
$check((string) $second->fresh()->status === Question::STATUS_NEW, 'empty answer publishes the question');

$questions->reject($second);
$check((string) $second->fresh()->status === Question::STATUS_REJECTED, 'reject does not change the status');
$check(Questions::count($product) === 1, 'rejected question is counted');

// Отзывы: приглашение по оплаченному заказу и одноразовая ссылка.
$reviews = new Reviews();

$check(Reviews::delayDays() >= 1 && Reviews::delayDays() <= 90, 'review delay is out of bounds');

$order = Order::create([
    'order_no' => Order::nextNumber(),
    'product_id' => (int) $product->id,
    'product_name' => (string) $product->name,
    'plan' => 'single',
    'qty' => 1,
    'amount_cents' => 1000,
    'currency' => 'BYN',
    'status' => Order::STATUS_PAID,
    'customer_name' => 'Алексей Кузнецов',
    'email' => $email,
    'paid_at' => now()->subDays(10),
]);

$token = $reviews->createInvitation($order);
$order = $order->fresh();

$check(preg_match('~^[a-f0-9]{48}$~', $token) === 1, 'review token is not 48 hex chars');
$check($order->review_invited_at !== null, 'invitation date is not saved');
$check($order->review_token_expires_at !== null && $order->review_token_expires_at->isFuture(), 'review token does not expire in the future');
$check($reviews->orderByToken($token) !== null, 'paid order is not found by token');
$check($reviews->orderByToken(str_repeat('0', 48)) === null, 'unknown token finds an order');
$check($reviews->orderByToken('short') === null, 'malformed token finds an order');
$check(str_contains($reviews->invitationUrl($order), '/review/' . $token . '/'), 'invitation url is not canonical');

$order->forceFill(['review_token_expires_at' => now()->subDay()])->save();
$check($reviews->orderByToken($token) === null, 'expired token still finds the order');
$order->forceFill(['review_token_expires_at' => now()->addDays(30)])->save();

$unpaid = Order::create([
    'order_no' => Order::nextNumber(),
    'product_id' => (int) $product->id,
    'product_name' => (string) $product->name,
    'plan' => 'single',
    'qty' => 1,
    'amount_cents' => 1000,
    'currency' => 'BYN',
    'status' => Order::STATUS_PENDING,
    'customer_name' => 'Мария Иванова',
    'email' => $email,
]);

$unpaidToken = $reviews->createInvitation($unpaid);
$check($reviews->orderByToken($unpaidToken) === null, 'unpaid order is found by token');

$review = $reviews->submit($order->fresh(), [
    'author_name' => 'Алексей Кузнецов',
    'rating' => 5,
    'title' => 'Запустили за вечер',
    'text' => 'Установили на свой домен, запись и оплата работают. Поддержка ответила в тот же день.',
    'pros' => 'Готовые страницы',
    'cons' => 'Пришлось разобраться с почтой',
], '127.0.0.1');

$check($review instanceof Review, 'review is not created');
$check((string) $review->status === Review::STATUS_PENDING, 'new review is not on moderation');
$check($review->is_verified === true, 'review from a paid order is not verified');
$check($review->display_name === 'Алексей К.', 'review display name is not «Имя Ф.»');
$check($review->stars === [true, true, true, true, true], 'five-star review has the wrong stars');
$check($order->fresh()->review_token === null, 'review token is not cleared after submit');
$check($reviews->existsFor($order->fresh()) === true, 'submitted review is not found for the order');
$check($reviews->submit($order->fresh(), [
    'author_name' => 'Алексей Кузнецов',
    'rating' => 5,
    'text' => 'Повторный отзыв по тому же заказу.',
]) === null, 'second review for the same order is accepted');

$check(Reviews::summary($product)['count'] === 0, 'pending review is already counted');
$check(Reviews::approved($product)->count() === 0, 'pending review is already published');
$check(Reviews::aggregate($product) === null, 'aggregate rating exists without approved reviews');

$reviews->approve($review);
$review = $review->fresh();

$check((string) $review->status === Review::STATUS_APPROVED, 'approve does not change the status');
$check($review->published_at !== null, 'approved review has no publish date');
$check(Reviews::summary($product)['count'] === 1, 'approved review is not counted');
$check(Reviews::summary($product)['average'] === 5.0, 'approved review average is not 5.0');
$check(Reviews::approved($product)->count() === 1, 'approved review is not listed');

$aggregate = Reviews::aggregate($product);
$check($aggregate !== null, 'aggregate rating is missing with an approved review');
$check(($aggregate['ratingValue'] ?? '') === '5', 'aggregate rating value is not 5');
$check(($aggregate['reviewCount'] ?? '') === '1', 'aggregate rating count is not 1');

// Микроразметка: Product + AggregateRating + QAPage.
$page = new class {
    public $id = 'module';
};

Seo::setProduct($product);
$json = Seo::jsonld($page);

$check(str_contains($json, '"@type":"Product"'), 'product node is missing');
$check(str_contains($json, '"@type":"AggregateRating"'), 'aggregate rating node is missing');
$check(str_contains($json, '"reviewCount":"1"'), 'aggregate rating count is not in the markup');
$check(str_contains($json, '"@type":"QAPage"'), 'single question is not marked as QAPage');
$check(str_contains($json, 'Можно ли поставить раздел на два сайта?'), 'published question is missing from the markup');
$check(!str_contains($json, 'Как перенести данные из старой базы?'), 'rejected question leaked into the markup');

$reviews->reject($review);
$json = Seo::jsonld($page);
$check(!str_contains($json, '"@type":"AggregateRating"'), 'rejected review keeps the aggregate rating');
$check(Reviews::aggregate($product) === null, 'rejected review keeps the aggregate');

// Маршруты и письма.
$routes = array_keys(Route::getRoutes()->getRoutesByName());
$check(in_array('elv.kit.question.store', $routes, true), 'question store route is missing');
$check(in_array('elv.kit.review.store', $routes, true), 'review store route is missing');
$check(View::exists('elv.kit::mail.question-admin'), 'question admin mail view is missing');
$check(View::exists('elv.kit::mail.review-invite'), 'review invite mail view is missing');
$check(View::exists('elv.kit::mail.review-admin'), 'review admin mail view is missing');
$check(isset(Artisan::all()['elv:kit:review']), 'review command is not registered');

// Заголовки и robots: страница отзыва закрыта от индексации и кэша.
$headers = (new SecurityHeaders())->handle(Request::create('/review/' . str_repeat('a', 48) . '/'), function () {
    return response('ok');
});

$check((string) $headers->headers->get('X-Robots-Tag') === 'noindex, nofollow', '/review/ is not closed from indexing');
$check(str_contains((string) $headers->headers->get('Cache-Control'), 'no-store'), '/review/ is cacheable');
$check(str_contains(Seo::robots(), 'Disallow: /review/'), 'robots.txt does not disallow /review/');

$check(str_contains(Seo::robots(), 'Disallow: /tickets/'), 'robots.txt lost the tickets rule');

// Шаблоны темы.
$themePath = themes_path('elv-business');
$check(is_file($themePath . '/partials/product-qa.htm'), 'product-qa partial is missing');
$check(is_file($themePath . '/partials/product-reviews.htm'), 'product-reviews partial is missing');
$check(is_file($themePath . '/pages/review.htm'), 'review page is missing');
$check(is_file($themePath . '/assets/css/community.css'), 'community.css is missing');

$reviewPage = is_file($themePath . '/pages/review.htm')
    ? (string) file_get_contents($themePath . '/pages/review.htm')
    : '';
$check(str_contains($reviewPage, 'noindex, nofollow'), 'review page is not noindex');
$check(str_contains($reviewPage, 'url = "/review/:token"'), 'review page has the wrong url');

// Настройки выключают блоки.
$originalQa = Settings::get('qa_enabled');
$originalReviews = Settings::get('reviews_enabled');

Settings::set('qa_enabled', false);
Settings::set('reviews_enabled', false);

$check(Questions::enabled() === false, 'qa switch does not turn questions off');
$check(Reviews::enabled() === false, 'reviews switch does not turn reviews off');

Settings::set('qa_enabled', $originalQa);
Settings::set('reviews_enabled', $originalReviews);

// Уборка: удаляем только созданное проверкой.
$orderIds = Order::query()->whereIn('email', [$email])->pluck('id')->all();
Review::query()->whereIn('order_id', $orderIds)->delete();
Order::query()->whereIn('id', $orderIds)->delete();
Question::query()->where('product_id', (int) $product->id)->delete();
Product::query()->where('id', (int) $product->id)->delete();

if ($errors !== []) {
    fwrite(STDERR, "community checks failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK: community checks passed\n";
