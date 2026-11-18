<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Seo;

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

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: SEO checks passed' . PHP_EOL;
