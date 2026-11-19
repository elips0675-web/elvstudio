<?php

/**
 * static-chat-data — данные для статического Консультанта (demo-static и Pages).
 *
 * Сервер на GitHub Pages не исполняется, поэтому ответы собирает браузер:
 * этот файл отдаёт те же значения каталога и настроек, что читает Chat.php.
 * Запуск: php tools/static-chat-data.php > demo-static/chat-data.json
 */

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\Money;
use Elv\Kit\Classes\Slots;
use Elv\Kit\Models\Settings;

$lines = static function (string $raw): array {
    $result = [];

    foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
        $line = trim((string) preg_replace('/^\s*[-•*]\s*/u', '', trim($line)));

        if ($line !== '') {
            $result[] = $line;
        }
    }

    return $result;
};

$limitText = static function (int $limit): string {
    return match (true) {
        $limit <= 1 => 'один домен',
        $limit >= 1000 => 'любое число доменов',
        default => 'до ' . $limit . ' доменов',
    };
};

$plans = [];

foreach (Catalog::plans() as $plan) {
    $plans[] = [
        'key' => (string) $plan['key'],
        'label' => (string) $plan['label'],
        'price' => Money::format((int) $plan['price']),
        'period' => (string) $plan['period'],
        'updates' => (string) $plan['updates'],
        'support' => (string) $plan['support'],
        'domains' => $limitText(Catalog::planDomainLimit((string) $plan['key'])),
    ];
}

$modules = [];

foreach (Catalog::modules() as $module) {
    $modules[] = [
        'slug' => (string) $module->slug,
        'name' => (string) $module->name,
        'price' => (string) $module->price_label,
    ];
}

$booking = [];

foreach (Slots::services() as $service) {
    $booking[] = [
        'name' => (string) $service['name'],
        'duration' => (int) $service['duration'],
        'price' => (string) $service['price'],
    ];
}

$data = [
    'generated' => date('c'),
    'currency' => Money::currency(),
    'plans' => $plans,
    'modules' => $modules,
    'includes' => array_values(Catalog::includes()),
    'services' => $lines((string) Settings::get('chat_services')),
    'servicesUrl' => (string) Settings::get('chat_services_url'),
    'booking' => $booking,
    'demoUrl' => (string) Settings::get('demo_url'),
    'contacts' => [
        'email' => (string) Settings::get('email'),
        'phone' => (string) Settings::get('phone'),
        'telegram' => (string) Settings::get('telegram'),
    ],
];

echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
