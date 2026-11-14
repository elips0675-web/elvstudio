<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\Demo;
use Illuminate\Console\Command;

/**
 * ResetCommand — сбрасывает демо-контент и наполняет заново.
 *
 * Заказы, лицензии и подписчики не трогаются: это данные владельца, а не
 * демо. Защита от случайного запуска — подтверждение.
 */
class ResetCommand extends Command
{
    protected $signature = 'elv:kit:reset {--force : без подтверждения}';

    protected $description = 'Удаляет демо-контент Elv Kit и наполняет его заново.';

    public function handle()
    {
        if (!$this->option('force') && !$this->confirm('Удалить демо-каталог, статьи и брони?', false)) {
            $this->warn('Отменено.');

            return self::FAILURE;
        }

        Demo::purge();

        $created = Demo::seed();
        $slots = Demo::slots();

        $this->info(sprintf(
            'Сброшено и наполнено: товаров %d, статей %d, слотов %d.',
            $created['products'],
            $created['posts'],
            $slots
        ));

        return self::SUCCESS;
    }
}
