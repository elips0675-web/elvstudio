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
    public const VERSION = '1.0.0';

    public function path(): string
    {
        return storage_path('app/kit/elv-kit-' . self::VERSION . '.zip');
    }

    public function ensureArchive(): string
    {
        $path = $this->path();

        if (is_file($path) && filesize($path) > 0) {
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

        $this->addDirectory($zip, base_path('themes/elv-studio'), 'elv-kit/theme');
        $this->addDirectory($zip, base_path('plugins/elv/kit'), 'elv-kit/plugin');

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

    protected function addDirectory(\ZipArchive $zip, string $source, string $prefix): void
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

            if ($file->isDir()) {
                $zip->addEmptyDir($prefix . '/' . $relative);
                continue;
            }

            $zip->addFile($file->getPathname(), $prefix . '/' . $relative);
        }
    }

    protected function readme(): string
    {
        return "# Elv Kit " . self::VERSION . "\n\n"
            . "Тема `elv-studio` и плагин `Elv.Kit` для October CMS 4.4+.\n\n"
            . "## Установка\n"
            . "1. Скопируйте `theme/` в `themes/elv-studio/`, `plugin/` в `plugins/elv/kit/`.\n"
            . "2. Выполните `php artisan october:migrate`.\n"
            . "3. Включите тему: `ACTIVE_THEME=elv-studio` в `.env` или через Settings → CMS.\n"
            . "4. Настройте почту и платёжные ключи в Settings → Elv Kit.\n"
            . "5. Демо-данные: `php artisan elv:kit:demo`.\n"
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
