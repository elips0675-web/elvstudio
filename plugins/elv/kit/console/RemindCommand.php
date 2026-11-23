<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\BookingReminders;
use Illuminate\Console\Command;

/**
 * RemindCommand — письма клиенту и мастеру перед визитом.
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

    protected $description = 'Отправляет напоминания клиенту и мастеру о записи.';

    public function handle()
    {
        $option = $this->option('hours');
        $hours = $option !== null && $option !== ''
            ? BookingReminders::normalizeHours((int) $option)
            : BookingReminders::hours();

        $force = (bool) $this->option('force');

        if (!BookingReminders::enabled() && !BookingReminders::adminEnabled() && !$force) {
            $this->info('Напоминания выключены: вкладка «Запись» → «Напоминания о записи».');

            return self::SUCCESS;
        }

        $customers = BookingReminders::enabled() || $force
            ? BookingReminders::send($hours)
            : 0;
        $admins = BookingReminders::adminEnabled() || $force
            ? BookingReminders::sendAdmin($hours)
            : 0;

        $this->info(
            'Напоминаний отправлено: клиентам — ' . $customers
            . ', мастеру — ' . $admins
            . ' (за ' . $hours . ' ч)'
        );

        return self::SUCCESS;
    }
}
