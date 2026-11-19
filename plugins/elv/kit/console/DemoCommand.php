<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\Demo;
use Elv\Kit\Classes\Slots;
use Illuminate\Console\Command;

/**
 * DemoCommand — наполняет сайт демо-каталогом, статьями и слотами.
 *
 * Команда идемпотентна: повторный запуск не создаёт дублей, а только
 * досоздаёт недостающее и обновляет сетку слотов.
 */
class DemoCommand extends Command
{
    protected $signature = 'elv:kit:demo
        {--refresh-slots : только обновить сетку слотов}
        {--refresh-posts : перезаписать тексты демо-статей}
        {--refresh-catalog : обновить названия и описания разделов}
        {--days=21 : на сколько дней вперёд генерировать слоты}';

    protected $description = 'Наполняет Elv Kit демо-данными (каталог, статьи, слоты).';

    public function handle()
    {
        $days = max(1, (int) $this->option('days'));

        if (!$this->option('refresh-slots')) {
            $created = Demo::seed((bool) $this->option('refresh-posts'), (bool) $this->option('refresh-catalog'));
            Demo::contacts();
            Demo::faq();

            if (!Demo::hasPostsDataset()) {
                $this->warn('Статьи блога не входят в этот архив (plugin/demo/blog.json): блог останется пустым.');
            }

            if ($this->option('refresh-posts')) {
                $this->info('Тексты демо-статей обновлены из demo/blog.json.');
            }

            if ($this->option('refresh-catalog')) {
                $this->info('Названия и описания разделов обновлены из классов\Demo.');
            }

            $this->info(sprintf(
                'Демо-данные: категорий %d, товаров %d, статей %d.',
                $created['categories'],
                $created['products'],
                $created['posts']
            ));
        }

        Demo::bookingServices();

        $slots = Demo::slots($days);
        $pruned = Slots::prune();

        $this->info(sprintf('Слоты: создано %d, удалено прошедших %d.', $slots, $pruned));

        return self::SUCCESS;
    }
}
