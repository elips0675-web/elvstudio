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

$refusal = Chat::answer('хуй');
$check($refusal['answer'] === TextFilter::REFUSAL, 'profanity refusal is not returned');
$check($refusal['sources'] === [], 'profanity refusal must not expose search sources');

$pricing = Chat::answer('сколько стоит');
$check(str_contains($pricing['answer'], 'Тарифы Elv Kit'), 'pricing answer is missing');
$check(($pricing['link']['label'] ?? '') === 'Сравнить тарифы', 'pricing link is missing');
$check($pricing['sources'] === [], 'pricing answer must not add unrelated sources');

$search = Chat::answer('найди статью про оплату');
$check(str_contains($search['answer'], 'Нашёл материалы'), 'explicit search answer is missing');
$check(count($search['sources']) >= 1, 'explicit search returns no sources');
$check(count($search['sources']) <= 3, 'explicit search returns more than three sources');

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
