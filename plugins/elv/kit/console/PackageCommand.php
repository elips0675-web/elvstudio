<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\DownloadService;
use Illuminate\Console\Command;

/**
 * PackageCommand — собирает ZIP темы и плагина для выдачи покупателю.
 */
class PackageCommand extends Command
{
    protected $signature = 'elv:kit:package {--rebuild : пересобрать архив, даже если он уже есть}';

    protected $description = 'Собирает ZIP Elv Kit (тема + плагин) в storage/app/kit.';

    public function handle()
    {
        $service = new DownloadService();
        $path = $service->path();

        if ($this->option('rebuild') && is_file($path)) {
            @unlink($path);
        }

        try {
            $path = $service->ensureArchive();
        }
        catch (\Throwable $e) {
            $this->error('Архив не собран: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Архив готов: ' . $path);
        $this->line('Размер: ' . number_format(filesize($path) / 1024, 1, ',', ' ') . ' КБ');

        return self::SUCCESS;
    }
}
