<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Release;
use Illuminate\Support\Facades\Log;

/**
 * DownloadService — ZIP пакета для оплаченного заказа.
 *
 * Файл собирается из актуальных каталогов темы и плагина, поэтому покупатель
 * не получает устаревший архив. Сборка кэшируется, пересобирается командой
 * elv:kit:package или при отсутствии файла.
 */
class DownloadService
{
    /**
     * Сколько раз один заказ может скачать архив за сутки, если владелец
     * не задал значение в настройках. 0 в настройке — без ограничения.
     */
    public const DOWNLOAD_LIMIT_DEFAULT = 5;

    public static function version(): string
    {
        static $version;

        if ($version !== null) {
            return $version;
        }

        $version = '0.0.0';
        $file = dirname(__DIR__) . '/updates/version.yaml';

        if (is_file($file)
            && preg_match_all('~^([0-9]+(?:\.[0-9]+)*)\s*:~m', (string) file_get_contents($file), $matches)) {
            foreach ($matches[1] as $candidate) {
                if (version_compare($candidate, $version, '>')) {
                    $version = $candidate;
                }
            }
        }

        return $version;
    }

    public function path(bool $demo = false): string
    {
        return storage_path('app/kit/elv-kit-' . self::version() . ($demo ? '-demo' : '') . '.zip');
    }

    public function ensureArchive(bool $demo = false, bool $force = false): string
    {
        $path = $this->path($demo);

        if (!$force && is_file($path) && filesize($path) > 0) {
            return $path;
        }

        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Расширение ext-zip не установлено.');
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Не удалось открыть ZIP для записи.');
        }

        $this->addDirectory($zip, base_path('themes/elv-studio'), 'elv-kit/theme', $demo);
        $this->addDirectory($zip, base_path('plugins/elv/kit'), 'elv-kit/plugin', $demo);

        $zip->addFromString('elv-kit/README.md', $this->readme());
        $zip->addFromString('elv-kit/LICENSE.txt', $this->licenseText());

        $zip->close();

        Log::info('Elv Kit: собран пакет', ['path' => $path, 'size' => filesize($path)]);

        return $path;
    }

    public function forOrder(Order $order): string
    {
        if (!$order->isPaid()) {
            throw new \RuntimeException('Заказ не оплачен.');
        }

        return $this->personalizedArchive($order);
    }

    /**
     * archiveForDelivery — действующая сборка релиза или свежий архив.
     *
     * Если в админке отмечена действующая сборка и файл на месте, покупатель
     * получает именно её: так владелец может зафиксировать версию и не
     * зависеть от правок, сделанных после публикации.
     */
    public function archiveForDelivery(bool $demo = false): string
    {
        $release = null;

        try {
            $release = Release::query()
                ->where('product_slug', 'elv-kit')
                ->where('is_demo', $demo)
                ->current()
                ->latest('published_at')
                ->first();
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: релизы недоступны, архив собирается заново', ['error' => $e->getMessage()]);
        }

        if ($release && $release->file_exists) {
            return $release->absolute_path;
        }

        if ($release) {
            Log::warning('Elv Kit: файл действующего релиза не найден, архив собирается заново', [
                'version' => $release->version,
                'file' => $release->file,
            ]);
        }

        return $this->ensureArchive($demo);
    }

    /**
     * registerRelease — записывает собранный архив как релиз.
     *
     * Версия одна: повторная сборка той же версии обновляет размер и
     * контрольную сумму, а не создаёт дубль. Файл хранится относительно
     * storage/app, чтобы запись пережила перенос стенда.
     */
    public function registerRelease(string $path, bool $demo = false, ?string $notes = null): Release
    {
        $file = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', storage_path('app')), '/') . '/';

        if (str_starts_with($file, $root)) {
            $file = substr($file, strlen($root));
        }

        $release = Release::query()->firstOrNew([
            'product_slug' => 'elv-kit',
            'version' => self::version(),
            'is_demo' => $demo,
        ]);

        $release->channel = $release->channel ?: 'stable';
        $release->file = $file;
        $release->size = (int) filesize($path);
        $release->checksum = (string) hash_file('sha256', $path);
        $release->published_at = now();
        $release->is_current = true;

        if ($notes !== null && trim($notes) !== '') {
            $release->notes = trim($notes);
        }

        $release->save();

        return $release;
    }

    /**
     * dailyLimit — суточный лимит скачиваний из настроек.
     */
    public function dailyLimit(): int
    {
        $value = \Elv\Kit\Models\Settings::get('download_daily_limit');

        if ($value === null || $value === '') {
            return self::DOWNLOAD_LIMIT_DEFAULT;
        }

        return max(0, (int) $value);
    }

    public function downloadsToday(Order $order): int
    {
        $counts = (array) (((array) $order->meta)['downloads'] ?? []);

        return (int) ($counts[now()->toDateString()] ?? 0);
    }

    public function canDownload(Order $order): bool
    {
        $limit = $this->dailyLimit();

        return $limit === 0 || $this->downloadsToday($order) < $limit;
    }

    /**
     * registerDownload — считает выдачу архива и отмечает доставку.
     *
     * Счётчик живёт в meta заказа по датам: лимит суточный, история нужна
     * для разбора «не пришёл файл». Храним 30 дней, старые даты вычищаем.
     */
    public function registerDownload(Order $order): void
    {
        $meta = (array) $order->meta;
        $counts = (array) ($meta['downloads'] ?? []);
        $today = now()->toDateString();

        $counts[$today] = (int) ($counts[$today] ?? 0) + 1;
        $keep = now()->subDays(30)->toDateString();
        $counts = array_filter($counts, fn ($date) => (string) $date >= $keep, ARRAY_FILTER_USE_KEY);

        $meta['downloads'] = $counts;
        $order->meta = $meta;

        if (!$order->delivered_at) {
            $order->delivered_at = now();
        }

        $order->save();
    }

    /**
     * personalizedArchive — копия архива заказа с LICENSE_OWNER.txt.
     *
     * Базовый ZIP общий для всех покупателей, поэтому имя владельца и номер
     * заказа дописываются в копию: утёкший архив можно связать с заказом.
     */
    protected function personalizedArchive(Order $order): string
    {
        $base = $this->archiveForDelivery();
        $path = $this->personalizedPath($order);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (!copy($base, $temporary)) {
            throw new \RuntimeException('Не удалось подготовить архив заказа.');
        }

        $zip = new \ZipArchive();

        if ($zip->open($temporary) !== true) {
            @unlink($temporary);

            throw new \RuntimeException('Не удалось открыть архив заказа.');
        }

        $zip->deleteName('elv-kit/LICENSE_OWNER.txt');
        $zip->addFromString('elv-kit/LICENSE_OWNER.txt', $this->ownerNotice($order));
        $zip->close();

        if (!rename($temporary, $path)) {
            @unlink($temporary);

            throw new \RuntimeException('Не удалось сохранить архив заказа.');
        }

        return $path;
    }

    public function personalizedPath(Order $order): string
    {
        $number = preg_replace('~[^A-Za-z0-9_-]~', '', (string) $order->order_no);
        $number = $number !== '' ? $number : 'order-' . $order->id;

        return storage_path('app/kit/orders/' . $number . '-v' . self::version() . '.zip');
    }

    protected function ownerNotice(Order $order): string
    {
        $license = License::query()->where('order_id', $order->id)->first();
        $paidAt = $order->paid_at ? $order->paid_at->format('d.m.Y H:i') : '—';

        return implode("\n", [
            'Elv Kit ' . self::version() . ' — архив покупателя',
            '',
            'Заказ: ' . $order->order_no . ' от ' . $paidAt,
            'Покупатель: ' . (string) $order->customer_name . ' <' . (string) $order->email . '>',
            'Тариф: ' . $order->plan_label,
            'Ключ лицензии: ' . ($license ? $license->license_key : 'будет выдан после оплаты'),
            'Скачано: ' . now()->format('d.m.Y H:i'),
            '',
            'Архив выдан по подписке на заказ. Передавать его третьим лицам нельзя:',
            'номер заказа в этом файле позволяет связать копию с покупкой.',
            '',
        ]);
    }

    protected function addDirectory(\ZipArchive $zip, string $source, string $prefix, bool $demo = false): void
    {
        if (!is_dir($source)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        $skip = ['storage', 'tests', '.git', 'node_modules', 'vendor'];

        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $parts = explode('/', $relative);

            if (array_intersect($parts, $skip)) {
                continue;
            }

            if (!$demo && preg_match('~^updates/seed_kit_[a-z0-9_]+\.php$~', $relative)) {
                continue;
            }

            if (!$demo && ($relative === 'demo' || str_starts_with($relative, 'demo/'))) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($prefix . '/' . $relative);
                continue;
            }

            if (!$demo && $relative === 'updates/version.yaml') {
                $zip->addFromString($prefix . '/' . $relative, $this->productVersionFile($file->getPathname()));
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
        }
    }

    protected function productVersionFile(string $path): string
    {
        $lines = preg_split('~\R~u', (string) file_get_contents($path)) ?: [];
        $result = [];

        foreach ($lines as $line) {
            if (preg_match('~^\s*-\s*seed_kit_[a-z0-9_]+\.php\s*$~u', $line)) {
                continue;
            }

            $result[] = $line;
        }

        return implode("\n", $result) . "\n";
    }

    protected function readme(): string
    {
        return "# Elv Kit " . self::version() . "\n\n"
            . "Тема `elv-studio` и плагин `Elv.Kit` для October CMS 4.4+.\n\n"
            . "## Установка\n"
            . "1. Скопируйте `theme/` в `themes/elv-studio/`, `plugin/` в `plugins/elv/kit/`.\n"
            . "2. Выполните `php artisan october:migrate`.\n"
            . "3. Включите тему: `ACTIVE_THEME=elv-studio` в `.env` или через Settings → CMS.\n"
            . "4. Настройте почту и платёжные ключи в Settings → Elv Kit.\n"
            . "5. Демо-данные (не обязательны): `php artisan elv:kit:demo` — заливает\n"
            . "   демонстрационные разделы, товары, контакты и FAQ. Статьи блога лежат\n"
            . "   в `plugin/demo/blog.json` и входят только в архив `--demo`: после\n"
            . "   установки товарного архива блог пуст и наполняется вашими статьями.\n"
            . "6. Cron: `php artisan schedule:run` раз в минуту.\n\n"
            . "## Лицензия\nСм. LICENSE.txt. Ключ активации выдаётся после оплаты.\n";
    }

    protected function licenseText(): string
    {
        return "Elv Kit License 1.0\n\n"
            . "Разрешено использовать пакет на количестве сайтов, указанном в лицензии.\n"
            . "Запрещена перепродажа пакета как товара и публикация исходников в открытом доступе.\n"
            . "Пакет предоставляется «как есть», без гарантии совместимости с изменённым ядром October CMS.\n";
    }
}
