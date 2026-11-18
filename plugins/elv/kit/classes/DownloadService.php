<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Order;
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

        return $this->ensureArchive();
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
            . "   демонстрационные разделы, товары и статьи. В архиве без флага `--demo`\n"
            . "   миграций с демо-данными нет: после `october:migrate` каталог и блог пустые.\n"
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
