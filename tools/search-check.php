<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Search;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$index = Search::index();
$check(count($index) >= 90, 'search index is smaller than expected');
$check(Search::index() === $index, 'search index cache returns a different array');

$longest = 0;
$hasLongText = false;

foreach ($index as $item) {
    $length = mb_strlen((string) ($item['text'] ?? ''));
    $longest = max($longest, $length);

    if ($length > 320) {
        $hasLongText = true;
    }

    $check($length <= 801, 'search text is longer than the index limit: ' . (string) ($item['url'] ?? ''));
}

$check($hasLongText, 'search index still truncates every article at 320 characters');
$check($longest <= 801, 'search index text limit is not 800 characters');

foreach (['оплата', 'оплатить', 'тарифами', 'цены', 'настройки', 'хостинг'] as $query) {
    $check(Search::query($query, Search::TYPE_ALL, 10) !== [], 'search returns nothing for: ' . $query);
}

$boundaryUrls = array_column(Search::query('став', Search::TYPE_ALL, 20), 'url');
$check(
    !in_array('/blog/sajt-dlya-lokalnoj-dostavki/', $boundaryUrls, true),
    'search matches «став» inside «доставка», not at a word start'
);
$check(
    array_intersect(['/blog/og-kartinki-na-php/', '/blog/kak-vybrat-hosting/'], $boundaryUrls) !== [],
    'search lost word-start matches for «став»'
);

$pricing = Search::query('тарифами', Search::TYPE_ALL, 10);
$pricingUrls = array_column($pricing, 'url');
$check(in_array('/pricing/', $pricingUrls, true), 'morphology does not find /pricing/ for «тарифами»');

$docs = Search::query('настройки', Search::TYPE_ALL, 10);
$docsUrls = array_column($docs, 'url');
$check(in_array('/docs/', $docsUrls, true), 'morphology does not find /docs/ for «настройки»');

Search::flush();
$check(Search::index() !== [], 'search index is empty after flush');

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: search checks passed' . PHP_EOL;
