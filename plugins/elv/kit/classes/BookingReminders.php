<?php namespace Elv\Kit\Classes;

use Carbon\Carbon;
use Elv\Kit\Classes\Mail\BookingReminder;
use Elv\Kit\Models\Booking;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BookingReminders — письма-напоминания клиенту перед визитом.
 *
 * Настройки: `booking_reminder_enabled` (вкладка «Запись») и
 * `booking_reminder_hours` — за сколько часов до визита писать (1–72, по
 * умолчанию 24). Отметка `reminded_at` в брони не даёт отправить письмо
 * дважды; отменённые брони и записи без e-mail пропускаются.
 */
class BookingReminders
{
    public const DEFAULT_HOURS = 24;
    public const MIN_HOURS = 1;
    public const MAX_HOURS = 72;

    public static function enabled(): bool
    {
        return (bool) Settings::get('booking_reminder_enabled', false);
    }

    public static function hours(): int
    {
        $hours = (int) Settings::get('booking_reminder_hours', self::DEFAULT_HOURS);

        return self::normalizeHours($hours);
    }

    public static function normalizeHours(int $hours): int
    {
        if ($hours <= 0) {
            return self::DEFAULT_HOURS;
        }

        return max(self::MIN_HOURS, min(self::MAX_HOURS, $hours));
    }

    /**
     * Брони, которым пора напомнить: не отменены, e-mail есть, отметки нет,
     * визит в ближайшие $hours часов.
     *
     * @return \Illuminate\Support\Collection<int, Booking>
     */
    public static function pending(int $hours, ?Carbon $now = null)
    {
        $now = $now ?: Carbon::now();
        $until = $now->copy()->addHours(self::normalizeHours($hours));

        return Booking::query()
            ->whereNull('reminded_at')
            ->where('status', '<>', Booking::STATUS_CANCELLED)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->filter(function (Booking $booking) use ($now, $until) {
                $at = self::moment($booking);

                return $at !== null
                    && $at->greaterThanOrEqualTo($now)
                    && $at->lessThanOrEqualTo($until);
            })
            ->values();
    }

    public static function moment(Booking $booking): ?Carbon
    {
        $date = trim((string) $booking->date);
        $time = trim((string) $booking->time);

        if ($date === '' || $time === '') {
            return null;
        }

        try {
            return Carbon::parse($date . ' ' . $time);
        }
        catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Отправляет напоминания и ставит отметку. Возвращает число писем.
     */
    public static function send(int $hours, ?Carbon $now = null): int
    {
        $sent = 0;

        foreach (self::pending($hours, $now) as $booking) {
            try {
                Mail::to($booking->email)->queue(new BookingReminder($booking));
                $booking->reminded_at = Carbon::now();
                $booking->save();
                $sent++;
            }
            catch (\Throwable $e) {
                Log::warning('Elv Kit: напоминание о записи не отправлено', [
                    'booking' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}
