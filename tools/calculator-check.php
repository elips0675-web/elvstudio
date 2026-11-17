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

echo "OK: calculator plans=" . count($data['plans'])
    . " modules=" . count($data['modules'])
    . " base=" . $base['launch']
    . " with=" . $with['launch']
    . "\n";
