<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Slots;
use Elv\Kit\Models\Settings;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$settings = Settings::instance();
$backup = $settings->getAttribute('booking_services');

$settings->forceFill(['booking_services' => '']);
$check(Slots::services() === [], 'empty settings still return booking services');
$check(!str_contains(json_encode(Slots::services(), JSON_UNESCAPED_UNICODE) ?: '', '120 BYN'), 'empty settings leak the demo price');
$check(Slots::service('consult') === null, 'empty settings still resolve the demo service code');

$settings->forceFill([
    'booking_services' => "haircut | Стрижка | 60 | 40 BYN | Коротко о процедуре | scissors\n"
        . "massage | Массаж | 90 | 80 BYN",
]);
$services = Slots::services();
$check(count($services) === 2, 'booking services are not parsed from settings');
$check(($services[0]['code'] ?? '') === 'haircut', 'booking service code is not parsed');
$check(($services[0]['name'] ?? '') === 'Стрижка', 'booking service name is not parsed');
$check((int) ($services[0]['duration'] ?? 0) === 60, 'booking service duration is not parsed');
$check(($services[0]['price'] ?? '') === '40 BYN', 'booking service price is not parsed');
$check(($services[0]['description'] ?? '') === 'Коротко о процедуре', 'booking service description is missing');
$check(($services[0]['icon'] ?? '') === 'scissors', 'booking service icon is missing');
$check(($services[1]['icon'] ?? 'x') === '', 'missing icon must stay empty');
$check(($services[1]['description'] ?? 'x') === '', 'missing description must stay empty');
$check((Slots::service('massage')['name'] ?? '') === 'Массаж', 'service lookup does not use settings');

$settings->forceFill([
    'booking_services' => "мусор без полей\n"
        . "broken | Без часа и цены\n"
        . "bad code! | Услуга | 0 | 10 BYN | x\n"
        . "ok | Норм | 30 | 0 BYN",
]);
$check(count(Slots::services()) === 1, 'malformed booking lines are not skipped');

$settings->forceFill(['booking_services' => "ok | Норм | 5 | 0 BYN\nlong | Долго | 481 | 0 BYN\nzero | Ноль | 4 | 0 BYN"]);
$check(count(Slots::services()) === 1, 'duration bound is not enforced');

$settings->forceFill(['booking_services' => "# комментарий\nok | Норм | 30 | 0 BYN | Труба | pipe"]);
$check(count(Slots::services()) === 1, 'comment line is not skipped');

$settings->forceFill(['booking_services' => "ok | Норм | 30 | 0 BYN | Труба | pipe"]);
$lines = Slots::servicesToLines(Slots::services());
$check(str_contains($lines, ' | '), 'settings lines must keep the pipe separator');
$check((Slots::parseServices($lines) === Slots::services()), 'settings lines do not round-trip');

$config = Slots::configServices();
$check(count($config) > 0, 'demo booking dataset is missing');
$check(Slots::servicesToLines($config) !== '', 'demo booking dataset is not convertible to settings lines');

$settings->forceFill(['booking_services' => $backup]);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: booking checks passed' . PHP_EOL;
