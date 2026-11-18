<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\DownloadService;
use Illuminate\Console\Command;

/**
 * PackageCommand — собирает ZIP темы и плагина для выдачи покупателю.
 */
class PackageCommand extends Command
{
    protected $signature = 'elv:kit:package
        {--rebuild : пересобрать архив, даже если он уже есть}
        {--demo : собрать вариант с демо-данными (для лендинга)}';

    protected $description = 'Собирает ZIP Elv Kit (тема + плагин) в storage/app/kit.';

    public function handle()
    {
        $demo = (bool) $this->option('demo');
        $service = new DownloadService();
        $path = $service->path($demo);

        if ($this->option('rebuild') && is_file($path)) {
            @unlink($path);
        }

        try {
            $path = $service->ensureArchive($demo, (bool) $this->option('rebuild'));
        }
        catch (\Throwable $e) {
            $this->error('Архив не собран: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('Архив готов: ' . $path);
        $this->line('Вариант: ' . ($demo ? 'с демо-данными' : 'без демо-данных'));
        $this->line('Размер: ' . number_format(filesize($path) / 1024, 1, ',', ' ') . ' КБ');

        return self::SUCCESS;
    }
}
