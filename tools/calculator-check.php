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

if ($data['modules'] !== []) {
    fwrite(STDERR, "FAIL: calculator must not expose separately sold modules\n");
    exit(1);
}

$plan = $data['plans'][0];
$base = Calculator::estimate($plan['key'], []);

if ($base['launch'] !== $plan['price']) {
    fwrite(STDERR, "FAIL: empty estimate differs from plan price\n");
    exit(1);
}

if ($base['annual'] !== 0 || $base['monthly'] !== 0 || $base['first_year'] !== $base['launch']) {
    fwrite(STDERR, "FAIL: annual/monthly/first-year formula\n");
    exit(1);
}

if (count($base['breakdown']) !== 1 || $base['breakdown'][0]['type'] !== 'plan') {
    fwrite(STDERR, "FAIL: breakdown must contain the plan row only\n");
    exit(1);
}

if ($base['modules'] !== []) {
    fwrite(STDERR, "FAIL: estimate must not return modules\n");
    exit(1);
}

$legacy = Calculator::estimate($plan['key'], ['booking', 'shop', 'missing-module']);

if ($legacy['launch'] !== $plan['price']) {
    fwrite(STDERR, "FAIL: legacy module slugs changed the total\n");
    exit(1);
}

if ($legacy['modules'] !== [] || count($legacy['breakdown']) !== 1) {
    fwrite(STDERR, "FAIL: legacy module slugs leaked into estimate\n");
    exit(1);
}

$planKeys = array_column($data['plans'], 'key');

foreach ($data['recommendations'] as $task => $recommendation) {
    if (!in_array($recommendation['plan'], $planKeys, true)) {
        fwrite(STDERR, "FAIL: recommendation {$task} points to a missing plan\n");
        exit(1);
    }

    if (!empty($recommendation['modules'])) {
        fwrite(STDERR, "FAIL: recommendation {$task} still recommends separately sold modules\n");
        exit(1);
    }
}

$quote = Calculator::quote($plan['key'], ['booking', 'shop'], 'services', 'own');

if (!preg_match('/^ELV-\d{8}-[A-F0-9]{6}$/', $quote['number'])) {
    fwrite(STDERR, "FAIL: quote number format\n");
    exit(1);
}

if ($quote['launch'] !== $plan['price'] || $quote['first_year'] !== $plan['price']) {
    fwrite(STDERR, "FAIL: quote total differs from plan price\n");
    exit(1);
}

if ($quote['modules'] !== []) {
    fwrite(STDERR, "FAIL: quote must not list separately sold modules\n");
    exit(1);
}

if (count($quote['breakdown']) !== 1) {
    fwrite(STDERR, "FAIL: quote breakdown must contain the plan row only\n");
    exit(1);
}

if ($quote['valid_days'] !== 14 || $quote['is_offer'] !== false) {
    fwrite(STDERR, "FAIL: quote validity or offer flag\n");
    exit(1);
}

$quoteRepeat = Calculator::quote($plan['key'], [], 'services', 'own');

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
    . " quote=" . $quote['number']
    . "\n";
