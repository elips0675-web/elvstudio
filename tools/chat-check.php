<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Chat;
use Elv\Kit\Classes\TextFilter;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

foreach (['хуй', 'х у й', 'xyй', 'пиззда', 'fuck'] as $word) {
    $check(TextFilter::has($word), 'filter misses: ' . $word);
}

foreach (['хутор', 'нормальный вопрос', 'установка'] as $word) {
    $check(!TextFilter::has($word), 'filter false positive: ' . $word);
}

foreach (['shitake', 'dickens', 'pussycat', 'cocktail'] as $word) {
    $check(!TextFilter::has($word), 'filter latin false positive: ' . $word);
}

foreach (['fuck', 'fucking', 'bitches', 'dicks', 'xyй', 'х у й'] as $word) {
    $check(TextFilter::has($word), 'filter latin/cyrillic miss: ' . $word);
}

$refusal = Chat::answer('хуй');
$check($refusal['answer'] === TextFilter::REFUSAL, 'profanity refusal is not returned');
$check($refusal['sources'] === [], 'profanity refusal must not expose search sources');
$check($refusal['rejected'] === true, 'profanity message must be rejected');

foreach (['абырвалг', 'фывап', 'абырвалг абырвалг', 'абырвалг оплата', 'фывап тарифы'] as $nonsense) {
    $reply = Chat::answer($nonsense);
    $check($reply['rejected'] === true, 'nonsense message is not rejected: ' . $nonsense);
    $check(str_contains($reply['answer'], 'Не понял'), 'nonsense answer is missing: ' . $nonsense);
    $check($reply['sources'] === [], 'nonsense answer must not expose sources: ' . $nonsense);
}

$crowdfunding = Chat::answer('краудфандинг');
$check($crowdfunding['rejected'] === false, 'known off-site term is rejected: краудфандинг');

$empty = Chat::answer('');
$check(str_contains($empty['answer'], 'подробнее'), 'empty message does not ask for details');

$long = Chat::answer(str_repeat('а', 10000));
$check(is_array($long) && $long['rejected'] === true, 'long nonsense is not rejected');

$services = Chat::answer('какие услуги вы оказываете');
$check(str_contains($services['answer'], 'Услуги Elv Studio'), 'studio services answer is missing');
$check(str_contains((string) ($services['link']['url'] ?? ''), '/services'), 'studio services link is missing');
$check(!str_contains($services['answer'], 'В демо-записи'), 'studio services answer mixes booking services');

$booking = Chat::answer('записаться на услугу');
$check(str_contains($booking['answer'], 'Онлайн-запись'), 'booking answer is missing');

$audit = Chat::answer('аудит сайта');
$check(str_contains((string) ($audit['link']['url'] ?? ''), '/services/audit'), 'audit link is missing');

$contact = Chat::answer('связаться с вами');
$check($contact['rejected'] === false, 'contact request is rejected');
$check(!str_contains($contact['answer'], 'Не понял'), 'contact request falls into nonsense');

$setup = Chat::answer('как настроить сайт');
$check(str_contains($setup['answer'], 'Настройка сайта'), 'site setup answer is missing');

$pricing = Chat::answer('сколько стоит');
$check(str_contains($pricing['answer'], 'Тарифы Elv Kit'), 'pricing answer is missing');
$check(($pricing['link']['label'] ?? '') === 'Сравнить тарифы', 'pricing link is missing');
$check($pricing['sources'] === [], 'pricing answer must not add unrelated sources');
$check($pricing['rejected'] === false, 'valid pricing question must not be rejected');

$search = Chat::answer('найди статью про оплату');
$check(str_contains($search['answer'], 'Нашёл материалы'), 'explicit search answer is missing');
$check(count($search['sources']) >= 1, 'explicit search returns no sources');
$check(count($search['sources']) <= 3, 'explicit search returns more than three sources');
$check($search['rejected'] === false, 'valid search must not be rejected');

$urls = [];

foreach ($search['sources'] as $source) {
    $check(str_starts_with((string) ($source['url'] ?? ''), 'http'), 'source url is not absolute: ' . ($source['label'] ?? ''));
    $check(in_array($source['kind'] ?? '', ['Статья', 'Страница'], true), 'source kind is unknown: ' . ($source['kind'] ?? ''));
    $urls[] = $source['url'] ?? '';
}

$check(count($urls) === count(array_unique($urls)), 'search sources contain duplicates');

$fallback = Chat::answer('как настроить CRM');
$check(str_contains($fallback['answer'], 'Точного ответа нет'), 'fallback did not use site search');
$check(count($fallback['sources']) >= 1, 'fallback search returns no sources');
$check($fallback['rejected'] === false, 'fallback with sources must not be rejected');

$nothing = Chat::answer('найди абракадабру без совпадений');
$check(str_contains($nothing['answer'], 'ничего не нашёл'), 'empty search does not report the miss');
$check($nothing['open'] === 'feedback', 'empty search must offer feedback');
$check($nothing['sources'] === [], 'empty search must not expose sources');

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: chat checks passed' . PHP_EOL;
