<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../bootstrap/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$strict = in_array('--strict', $argv ?? [], true);
$rows = [];

$add = function (string $level, string $label, string $detail = '') use (&$rows): void {
    $rows[] = [
        'level' => $level,
        'label' => $label,
        'detail' => $detail,
    ];
};

$root = dirname(__DIR__);

$add(PHP_VERSION_ID >= 80300 ? 'OK' : 'FAIL', 'PHP', PHP_VERSION);

foreach (['pdo_mysql', 'mbstring', 'json', 'gd'] as $extension) {
    $add(extension_loaded($extension) ? 'OK' : 'FAIL', 'Расширение ' . $extension, extension_loaded($extension) ? 'загружено' : 'не загружено');
}

$paths = [
    'storage',
    'storage/app',
    'storage/framework/cache',
    'storage/framework/sessions',
    'storage/framework/views',
    'storage/logs',
    'bootstrap/cache',
];

foreach ($paths as $path) {
    $absolute = $root . '/' . $path;
    $add(is_dir($absolute) && is_writable($absolute) ? 'OK' : 'FAIL', 'Права ' . $path, is_dir($absolute) ? (is_writable($absolute) ? 'запись есть' : 'нет записи') : 'каталога нет');
}

$htaccess = $root . '/.htaccess';

if (!is_file($htaccess)) {
    $add('FAIL', 'Apache .htaccess', 'файла нет');
} elseif (!str_contains((string) file_get_contents($htaccess), 'RewriteEngine On')) {
    $add('WARN', 'Apache .htaccess', 'нет RewriteEngine On');
} else {
    $add('OK', 'Apache .htaccess', 'RewriteEngine On');
}

$appKey = (string) env('APP_KEY');
$add($appKey !== '' ? 'OK' : 'FAIL', 'APP_KEY', $appKey !== '' ? 'задан' : 'пуст');

$appEnv = (string) env('APP_ENV', 'production');
$add($appEnv === 'production' ? 'OK' : 'WARN', 'APP_ENV', $appEnv);

$debug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOLEAN);
$add($debug ? 'WARN' : 'OK', 'APP_DEBUG', $debug ? 'true — на проде нужно false' : 'false');

$appUrl = (string) env('APP_URL');
$host = parse_url($appUrl, PHP_URL_HOST) ?: '';
$isLocal = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
$add($isLocal ? 'WARN' : 'OK', 'APP_URL', $appUrl !== '' ? $appUrl : 'пуст');

try {
    DB::connection()->getPdo();
    $add('OK', 'База данных', (string) config('database.default'));
} catch (\Throwable $error) {
    $add('FAIL', 'База данных', $error->getMessage());
}

$queue = (string) env('QUEUE_CONNECTION', (string) config('queue.default'));
$add($queue === 'sync' ? 'WARN' : 'OK', 'Очередь', $queue . ($queue === 'sync' ? ' — задачи выполняются сразу' : ''));

$mail = (string) env('MAIL_MAILER', (string) config('mail.default'));
$add($mail === 'log' ? 'WARN' : 'OK', 'Почта', $mail . ($mail === 'log' ? ' — письма в лог' : ''));

$paymentKeys = ['YOOKASSA_SHOP_ID', 'YOOKASSA_SECRET', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET'];
$missingPaymentKeys = array_values(array_filter($paymentKeys, static fn (string $key): bool => (string) env($key) === ''));
$add($missingPaymentKeys === [] ? 'OK' : 'WARN', 'Платёжные ключи', $missingPaymentKeys === [] ? 'заданы' : 'не заданы: ' . implode(', ', $missingPaymentKeys));

$add('WARN', 'Cron', 'проверяется на хостинге: php artisan schedule:run каждую минуту');

$width = 5;

foreach ($rows as $row) {
    $width = max($width, mb_strlen($row['label']));
}

$fail = 0;
$warn = 0;

foreach ($rows as $row) {
    if ($row['level'] === 'FAIL') {
        $fail++;
    } elseif ($row['level'] === 'WARN') {
        $warn++;
    }

    printf(
        "%-4s  %-{$width}s  %s\n",
        $row['level'],
        $row['label'],
        $row['detail']
    );
}

$ok = count($rows) - $fail - $warn;

echo "\n";
echo "Итог: {$ok} OK, {$warn} предупреждений, {$fail} ошибок.\n";

if ($strict && $warn > 0) {
    echo "Строгий режим: предупреждения считаются ошибками.\n";
    exit(1);
}

exit($fail > 0 ? 1 : 0);
