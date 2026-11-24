<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\TicketService;
use Elv\Kit\Models\Settings;
use Illuminate\Console\Command;

/**
 * AutocloseCommand — закрывает обращения, где покупатель молчит после ответа.
 *
 * Срок берётся из настроек (`ticket_autoclose_days`, по умолчанию 5 дней,
 * `0` — не закрывать). Обращения в работе не трогаются: если последнее слово
 * осталось за покупателем, поддержка ещё должна ответить.
 */
class AutocloseCommand extends Command
{
    protected $signature = 'elv:kit:autoclose
        {--days= : срок молчания покупателя в днях, перекрывает настройку}
        {--dry : только показать, сколько обращений будет закрыто}';

    protected $description = 'Закрывает обращения без ответа покупателя.';

    public function handle()
    {
        $days = $this->option('days');
        $days = $days === null ? (int) Settings::get('ticket_autoclose_days', 5) : (int) $days;

        if ($days <= 0) {
            $this->line('Автозакрытие выключено в настройках.');

            return 0;
        }

        if ($this->option('dry')) {
            $count = (new TicketService())->autocloseCount($days);
            $this->info('Будет закрыто обращений: ' . $count . ' (срок ' . $days . ' дн.).');

            return 0;
        }

        $closed = (new TicketService())->autoclose($days);
        $this->info('Закрыто обращений: ' . $closed . ' (срок ' . $days . ' дн.).');

        return 0;
    }
}
