<?php namespace Elv\Kit\Classes;

use Carbon\Carbon;

/**
 * Support — рабочее время поддержки и срок первого ответа.
 *
 * SLA считается по рабочим часам, а не по календарным суткам: обращение,
 * пришедшее в пятницу вечером, не должно «просрочиваться» за выходные.
 * Рабочее время — Пн–Пт 10:00–18:00 Europe/Minsk, срок — 24 рабочих часа.
 */
class Support
{
    public const TIMEZONE = 'Europe/Minsk';
    public const WORK_START = 10;
    public const WORK_END = 18;
    public const SLA_HOURS = 24;

    /**
     * dueAt — срок первого ответа от момента создания обращения.
     */
    public static function dueAt($start = null): Carbon
    {
        // Строка трактуется как местное время поддержки: `now()` приходит
        // в UTC приложения, а «пятница 17:30» в коде и тестах — это Минск.
        if ($start instanceof \DateTimeInterface) {
            $time = Carbon::instance($start)->setTimezone(self::TIMEZONE);
        }
        elseif ($start) {
            $time = Carbon::parse((string) $start, self::TIMEZONE);
        }
        else {
            $time = now()->setTimezone(self::TIMEZONE);
        }

        $remaining = self::SLA_HOURS * 3600;

        while ($remaining > 0) {
            $time = self::nextWorkingMoment($time);

            $endOfDay = $time->copy()->setTime(self::WORK_END, 0, 0);
            $available = $endOfDay->getTimestamp() - $time->getTimestamp();

            if ($available <= 0) {
                $time = self::nextWorkingMoment($endOfDay->copy()->addMinute());
                continue;
            }

            if ($available >= $remaining) {
                $time = $time->copy()->addSeconds($remaining);
                $remaining = 0;
                continue;
            }

            $remaining -= $available;
            $time = self::nextWorkingMoment($endOfDay);
        }

        return $time->setTimezone((string) config('app.timezone', 'UTC'));
    }

    /**
     * nextWorkingMoment — ближайшая минута внутри рабочего окна.
     */
    public static function nextWorkingMoment(Carbon $time): Carbon
    {
        $time = $time->copy()->setTimezone(self::TIMEZONE);

        while (true) {
            if (!self::isWorkday($time)) {
                $time = $time->copy()->addDay()->setTime(self::WORK_START, 0, 0);
                continue;
            }

            if ($time->hour < self::WORK_START) {
                return $time->setTime(self::WORK_START, 0, 0);
            }

            if ($time->hour >= self::WORK_END) {
                $time = $time->copy()->addDay()->setTime(self::WORK_START, 0, 0);
                continue;
            }

            return $time;
        }
    }

    public static function isWorkday(Carbon $time): bool
    {
        return $time->dayOfWeekIso >= 1 && $time->dayOfWeekIso <= 5;
    }

    public static function isWorkingMoment(Carbon $time): bool
    {
        $time = $time->copy()->setTimezone(self::TIMEZONE);

        return self::isWorkday($time)
            && $time->hour >= self::WORK_START
            && $time->hour < self::WORK_END;
    }

    /**
     * label — срок в часовом поясе поддержки, без машинного UTC.
     */
    public static function label($time): string
    {
        if (!$time instanceof Carbon) {
            return '';
        }

        return $time->copy()->setTimezone(self::TIMEZONE)->format('d.m.Y H:i');
    }

    /**
     * isOverdue — первый ответ не отправлен, а срок уже прошёл.
     */
    public static function isOverdue($ticket): bool
    {
        if (!$ticket || !$ticket->sla_due_at instanceof Carbon) {
            return false;
        }

        if ($ticket->first_response_at instanceof Carbon) {
            return false;
        }

        if ((string) $ticket->status === 'closed') {
            return false;
        }

        return $ticket->sla_due_at->isPast();
    }

    /**
     * describe — человеческая строка для писем и админки.
     */
    public static function describe($ticket): string
    {
        if (!$ticket || !$ticket->sla_due_at instanceof Carbon) {
            return '';
        }

        if ($ticket->first_response_at instanceof Carbon) {
            return 'Первый ответ: ' . self::label($ticket->first_response_at);
        }

        return 'Первый ответ до: ' . self::label($ticket->sla_due_at);
    }
}
