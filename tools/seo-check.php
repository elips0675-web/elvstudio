<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Faq;
use Elv\Kit\Classes\Seo;
use Elv\Kit\Models\Settings;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$method = new ReflectionMethod(Seo::class, 'faqNodeFromItems');
$method->setAccessible(true);

$empty = $method->invoke(null, null, []);
$check($empty === [], 'empty FAQ source still builds a FAQPage node');

$filled = $method->invoke(null, null, [
    ['q' => 'Вопрос', 'a' => 'Ответ'],
]);
$check(($filled['@type'] ?? '') === 'FAQPage', 'FAQPage node is missing for a filled source');
$check(count($filled['mainEntity'] ?? []) === 1, 'FAQPage mainEntity is empty for a filled source');

$robots = Seo::robots();
$check(str_contains($robots, 'Clean-param: q&category&tag'), 'robots.txt misses the global Clean-param');
$check(!str_contains($robots, 'Clean-param: q&category&tag /blog/'), 'robots.txt still scopes Clean-param to /blog/');

$settings = Settings::instance();
$faqKeys = ['faq_items', 'faq_calculator_items', 'faq_services_items', 'faq_seo_items', 'faq_post_items'];
$faqBackup = [];

foreach ($faqKeys as $key) {
    $faqBackup[$key] = $settings->getAttribute($key);
}

$settings->forceFill(array_fill_keys($faqKeys, ''));
$check(Faq::items() === [], 'empty settings still return a FAQ block');
$check(Faq::items('calculator') === [], 'empty settings still return the calculator FAQ');
$check(Faq::post('kak-sdelat-sajt') === [], 'empty settings still return the article FAQ');

$settings->forceFill([
    'faq_items' => "Первый вопрос | Первый ответ\n\n# комментарий\nВторой вопрос | Второй ответ | с чертой в ответе\nмусор без разделителя",
]);
$items = Faq::items();
$check(count($items) === 2, 'FAQ pairs from settings are not parsed line by line');
$check(($items[0]['q'] ?? '') === 'Первый вопрос', 'FAQ question is not trimmed');
$check(($items[1]['a'] ?? '') === 'Второй ответ | с чертой в ответе', 'FAQ answer is cut at the first pipe');

$settings->forceFill(['faq_calculator_items' => 'Вопрос калькулятора | Ответ калькулятора']);
$check(count(Faq::items('calculator')) === 1, 'calculator FAQ key is not read from settings');
$check(count(Faq::items('services')) === 0, 'calculator FAQ leaks into the services block');

$settings->forceFill(['faq_post_items' => "demo-post | Вопрос статьи | Ответ статьи\nbroken post line"]);
$postItems = Faq::post('demo-post');
$check(count($postItems) === 1, 'article FAQ is not read from settings');
$check(($postItems[0]['q'] ?? '') === 'Вопрос статьи', 'article FAQ question is not parsed');
$check(Faq::post('other-post') === [], 'article FAQ leaks to another slug');

$check(count(Faq::configItems('faq')) > 0, 'demo FAQ dataset is missing');
$check(count(Faq::configItems('post-faq')) > 0, 'demo article FAQ dataset is missing');
$check(Faq::postToLines(Faq::configPostItems()) !== '', 'demo article FAQ is not convertible to settings lines');

foreach ($faqBackup as $key => $value) {
    $settings->forceFill([$key => $value]);
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: SEO checks passed' . PHP_EOL;
