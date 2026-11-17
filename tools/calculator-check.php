<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Calculator;

$data = Calculator::data();

if ($data['plans'] === []) {
    fwrite(STDERR, "FAIL: calculator has no plans\n");
    exit(1);
}

$plan = $data['plans'][0];
$modules = [];

foreach ($data['modules'] as $module) {
    if ($module['price'] > 0) {
        $modules[$module['slug']] = $module;
    }
}

$base = Calculator::estimate($plan['key'], []);

if ($base['launch'] !== $plan['price']) {
    fwrite(STDERR, "FAIL: empty estimate differs from plan price\n");
    exit(1);
}

if ($base['annual'] !== 0 || $base['monthly'] !== 0 || $base['first_year'] !== $base['launch']) {
    fwrite(STDERR, "FAIL: annual/monthly/first-year formula\n");
    exit(1);
}

$slugs = array_slice(array_keys($modules), 0, 2);
$with = Calculator::estimate($plan['key'], $slugs);
$expected = $plan['price'];

foreach ($slugs as $slug) {
    $expected += $modules[$slug]['price'];
}

if ($with['launch'] !== $expected) {
    fwrite(STDERR, "FAIL: modules are not added to launch\n");
    exit(1);
}

if (count($with['breakdown']) !== count($slugs) + 1) {
    fwrite(STDERR, "FAIL: breakdown must include plan and selected modules\n");
    exit(1);
}

$unknown = Calculator::estimate($plan['key'], ['missing-module']);
if ($unknown['launch'] !== $plan['price']) {
    fwrite(STDERR, "FAIL: unknown module changed the total\n");
    exit(1);
}

$planKeys = array_column($data['plans'], 'key');

foreach ($data['recommendations'] as $task => $recommendation) {
    if (!in_array($recommendation['plan'], $planKeys, true)) {
        fwrite(STDERR, "FAIL: recommendation {$task} points to a missing plan\n");
        exit(1);
    }

    foreach ($recommendation['modules'] as $slug) {
        if (!isset($modules[$slug])) {
            fwrite(STDERR, "FAIL: recommendation {$task} points to a missing paid module\n");
            exit(1);
        }
    }
}

$quote = Calculator::quote($plan['key'], $slugs, 'services', 'own');

if (!preg_match('/^ELV-\d{8}-[A-F0-9]{6}$/', $quote['number'])) {
    fwrite(STDERR, "FAIL: quote number format\n");
    exit(1);
}

if ($quote['launch'] !== $expected || $quote['first_year'] !== $expected) {
    fwrite(STDERR, "FAIL: quote total differs from estimate\n");
    exit(1);
}

if ($quote['valid_days'] !== 14 || $quote['is_offer'] !== false) {
    fwrite(STDERR, "FAIL: quote validity or offer flag\n");
    exit(1);
}

$quoteRepeat = Calculator::quote($plan['key'], $slugs, 'services', 'own');

if ($quoteRepeat['number'] === $quote['number']) {
    fwrite(STDERR, "FAIL: quote number is not unique per issue\n");
    exit(1);
}

foreach ($quote['breakdown'] as $row) {
    if (!isset($row['price_label']) || $row['price_label'] === '') {
        fwrite(STDERR, "FAIL: quote breakdown has no formatted price\n");
        exit(1);
    }
}

$html = view('elv.kit::quote-pdf', ['quote' => $quote])->render();

if (!str_contains($html, $quote['number']) || !str_contains($html, $quote['first_year_label'])) {
    fwrite(STDERR, "FAIL: quote PDF template misses number or total\n");
    exit(1);
}

echo "OK: calculator plans=" . count($data['plans'])
    . " modules=" . count($data['modules'])
    . " recommendations=" . count($data['recommendations'])
    . " base=" . $base['launch']
    . " with=" . $with['launch']
    . " quote=" . $quote['number']
    . "\n";
