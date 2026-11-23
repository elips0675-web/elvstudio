<?php

/**
 * download-check — выдача архива покупателю: лимит, водяной знак, доставка.
 *
 * Проверка идёт в транзакции с откатом: заказ, лицензия и настройки стенда
 * остаются прежними. Собранный ZIP заказа удаляется после проверки.
 * Запуск: php tools/download-check.php
 */

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\DownloadService;
use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Release;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\DB;

$errors = [];
$service = new DownloadService();
$archive = null;

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$backup = [
    'download_daily_limit' => Settings::get('download_daily_limit'),
];

$setSetting = function (string $value) {
    Settings::set(['download_daily_limit' => $value]);
    Settings::clearInternalCache();
    (new Settings())->clearCache();
};

DB::beginTransaction();

try {
    $check($service->dailyLimit() === DownloadService::DOWNLOAD_LIMIT_DEFAULT && (string) $backup['download_daily_limit'] === '', 'по умолчанию лимит не 5');
    $setSetting('3');
    $check($service->dailyLimit() === 3, 'dailyLimit не читает настройку');
    $setSetting('0');
    $check($service->dailyLimit() === 0, '0 должно означать «без ограничения»');
    $setSetting('abc');
    $check($service->dailyLimit() === 0, 'мусор в настройке должен давать 0, а не отрицательный лимит');

    $order = new Order();
    $order->order_no = 'CHECK-' . strtoupper(bin2hex(random_bytes(3)));
    $order->product_name = 'Elv Kit';
    $order->plan = 'single';
    $order->qty = 1;
    $order->amount_cents = 7900;
    $order->currency = 'USD';
    $order->status = Order::STATUS_PAID;
    $order->customer_name = 'Проверка Покупателя';
    $order->email = 'buyer@example.com';
    $order->paid_at = now();
    $order->download_token = bin2hex(random_bytes(24));
    $order->meta = [];
    $order->save();

    $license = new License();
    $license->license_key = License::generateKey();
    $license->product_slug = 'elv-kit';
    $license->email = $order->email;
    $license->order_id = $order->id;
    $license->domain_limit = 1;
    $license->status = License::STATUS_ACTIVE;
    $license->save();

    $base = $service->ensureArchive();
    $baseZip = new ZipArchive();

    if ($baseZip->open($base) === true) {
        $check($baseZip->locateName('elv-kit/LICENSE_OWNER.txt') === false, 'в общем архиве появился LICENSE_OWNER.txt');
        $baseZip->close();
    } else {
        $check(false, 'не удалось открыть общий архив');
    }

    $release = $service->registerRelease($base, false, 'download-check');
    $check($release->version === DownloadService::version(), 'релиз записан с чужой версией');
    $check($release->checksum === hash_file('sha256', $base), 'контрольная сумма релиза не совпала с файлом');
    $check($release->size === filesize($base), 'размер релиза не совпал с файлом');
    $check($release->is_current && $release->file_exists, 'собранный архив не стал действующим релизом');
    $check(realpath($service->archiveForDelivery()) === realpath($base), 'archiveForDelivery не вернул файл действующего релиза');
    $check($service->archiveForDelivery(true) !== $base, 'демо-архив берёт товарный релиз');

    $other = new Release();
    $other->product_slug = 'elv-kit';
    $other->version = '0.0.1-check';
    $other->channel = 'beta';
    $other->file = $release->file;
    $other->size = $release->size;
    $other->is_demo = false;
    $other->is_current = true;
    $other->published_at = now();
    $other->save();

    $check(!$release->fresh()->is_current, 'у товара осталось две действующих сборки');
    $release->is_current = true;
    $release->save();
    $check(!$other->fresh()->is_current, 'переключение действующей сборки не сняло прежнюю');

    $archive = $service->forOrder($order);
    $check(is_file($archive), 'forOrder не вернул файл архива');
    $check(filesize($archive) > filesize($base), 'архив заказа не больше общего');

    $zip = new ZipArchive();

    if ($archive && $zip->open($archive) === true) {
        $notice = (string) $zip->getFromName('elv-kit/LICENSE_OWNER.txt');
        $zip->close();

        $check($notice !== '', 'в архиве заказа нет LICENSE_OWNER.txt');
        $check(str_contains($notice, $order->order_no), 'водяной знак без номера заказа');
        $check(str_contains($notice, (string) $order->email), 'водяной знак без e-mail покупателя');
        $check(str_contains($notice, (string) $license->license_key), 'водяной знак без ключа лицензии');
        $check(str_contains($notice, DownloadService::version()), 'водяной знак без версии');
        $check(str_contains($notice, 'Тариф: '), 'водяной знак без тарифа');
    } else {
        $check(false, 'не удалось открыть архив заказа');
    }

    $setSetting('2');
    $check($service->canDownload($order), 'первое скачивание запрещено');
    $service->registerDownload($order);
    $check($service->downloadsToday($order) === 1, 'счётчик после первого скачивания не 1');
    $service->registerDownload($order);
    $check($service->downloadsToday($order) === 2, 'счётчик после второго скачивания не 2');
    $check(!$service->canDownload($order), 'второе скачивание не исчерпало лимит 2');
    $check($order->fresh()->delivered_at !== null, 'доставка не отмечена');

    $setSetting('0');
    $check($service->canDownload($order), 'лимит 0 должен разрешать скачивание');

    $meta = (array) $order->fresh()->meta;
    $counts = (array) ($meta['downloads'] ?? []);
    $check(isset($counts[now()->toDateString()]), 'в meta нет счётчика за сегодня');
}
catch (Throwable $error) {
    $errors[] = 'исключение: ' . $error->getMessage();
}
finally {
    if ($archive && is_file($archive)) {
        @unlink($archive);
    }

    DB::rollBack();
    $setSetting((string) $backup['download_daily_limit']);
}

$check((string) Settings::get('download_daily_limit') === (string) $backup['download_daily_limit'], 'настройка лимита не восстановилась');

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: лимит по умолчанию ' . DownloadService::DOWNLOAD_LIMIT_DEFAULT
    . ', водяной знак с заказом, ключом и версией, счётчик и доставка работают' . PHP_EOL;
