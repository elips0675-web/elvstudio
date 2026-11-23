<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\BookingReminders;
use Illuminate\Console\Command;

/**
 * RemindCommand — письма клиентам перед визитом.
 *
 * Запускается по расписанию раз в час (`Plugin::registerSchedule`). Если
 * напоминания выключены в настройках, команда ничего не делает и не падает.
 * `--hours` переопределяет настройку, `--force` отправляет при выключенной
 * настройке — для ручной проверки на стенде.
 */
class RemindCommand extends Command
{
    protected $signature = 'elv:kit:remind
        {--hours= : за сколько часов до визита (1–72)}
        {--force : отправить даже если напоминания выключены}';

    protected $description = 'Отправляет клиентам напоминания о записи.';

    public function handle()
    {
        $option = $this->option('hours');
        $hours = $option !== null && $option !== ''
            ? BookingReminders::normalizeHours((int) $option)
            : BookingReminders::hours();

        if (!BookingReminders::enabled() && !$this->option('force')) {
            $this->info('Напоминания выключены: вкладка «Запись» → «Напоминания о записи».');

            return self::SUCCESS;
        }

        $sent = BookingReminders::send($hours);
        $this->info('Напоминаний отправлено: ' . $sent . ' (за ' . $hours . ' ч)');

        return self::SUCCESS;
    }
}
