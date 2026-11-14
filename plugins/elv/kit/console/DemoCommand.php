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
        {--days=21 : на сколько дней вперёд генерировать слоты}';

    protected $description = 'Наполняет Elv Kit демо-данными (каталог, статьи, слоты).';

    public function handle()
    {
        $days = max(1, (int) $this->option('days'));

        if (!$this->option('refresh-slots')) {
            $created = Demo::seed();
            Demo::contacts();

            $this->info(sprintf(
                'Демо-данные: категорий %d, товаров %d, статей %d.',
                $created['categories'],
                $created['products'],
                $created['posts']
            ));
        }

        $slots = Demo::slots($days);
        $pruned = Slots::prune();

        $this->info(sprintf('Слоты: создано %d, удалено прошедших %d.', $slots, $pruned));

        return self::SUCCESS;
    }
}
