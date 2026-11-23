<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Slot;

/**
 * Slots — услуги записи и сетка свободного времени.
 *
 * Слоты лежат в базе, потому что `taken` меняется при брони. Сетку на
 * странице строим только из существующих строк: пустых «нарисованных»
 * часов, на которые нельзя записаться, быть не должно.
 */
class Slots
{
    public static function services(): array
    {
        return static::parseServices((string) Settings::get('booking_services'));
    }

    public static function configServices(): array
    {
        $path = base_path('plugins/elv/kit/config/booking.php');

        if (!is_file($path)) {
            return [];
        }

        $services = require $path;

        return is_array($services) ? $services : [];
    }

    public static function parseServices(string $raw): array
    {
        $services = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));

            if (count($parts) < 4) {
                continue;
            }

            $code = preg_replace('/[^a-z0-9-]/', '', strtolower($parts[0]));
            $name = static::clean($parts[1], 80);
            $duration = (int) $parts[2];
            $price = static::clean($parts[3], 60);

            if ($code === '' || $name === '' || $duration < 5 || $duration > 480 || $price === '') {
                continue;
            }

            $services[] = [
                'code' => $code,
                'name' => $name,
                'duration' => $duration,
                'price' => $price,
                'description' => static::clean($parts[4] ?? '', 400),
                'icon' => preg_replace('/[^a-z0-9-]/', '', strtolower($parts[5] ?? '')),
            ];
        }

        return $services;
    }

    public static function servicesToLines(array $services): string
    {
        $lines = [];

        foreach ($services as $service) {
            $code = (string) ($service['code'] ?? '');
            $name = static::clean((string) ($service['name'] ?? ''), 80);

            if ($code === '' || $name === '') {
                continue;
            }

            $description = static::clean((string) ($service['description'] ?? ''), 400);
            $icon = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($service['icon'] ?? '')));

            $parts = [
                $code,
                $name,
                (string) (int) ($service['duration'] ?? 0),
                static::clean((string) ($service['price'] ?? ''), 60),
            ];

            if ($icon !== '') {
                $parts[] = $description;
                $parts[] = $icon;
            } elseif ($description !== '') {
                $parts[] = $description;
            }

            $lines[] = implode(' | ', $parts);
        }

        return implode("\n", $lines);
    }

    protected static function clean(string $value, int $limit): string
    {
        return mb_substr(trim(str_replace('|', '/', strip_tags($value))), 0, $limit);
    }

    public static function service(string $code): ?array
    {
        foreach (static::services() as $service) {
            if ($service['code'] === $code) {
                return $service;
            }
        }

        return null;
    }

    /**
     * days — дни начиная с завтрашнего, в которых есть хотя бы один слот.
     *
     * @return array<int, array{date: string, label: string, weekday: string, slots: array<int, array{time: string, available: bool, left: int}>}>
     */
    public static function days(string $serviceCode, int $days = 14): array
    {
        if ($serviceCode === '') {
            return [];
        }

        $from = now()->addDay()->startOfDay();
        $to = $from->copy()->addDays(max(1, $days));

        $rows = Slot::query()
            ->where('service_code', $serviceCode)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->groupBy(function (Slot $slot) {
                return $slot->date;
            });

        $result = [];

        foreach ($rows as $date => $slots) {
            $available = $slots->filter(function (Slot $slot) {
                return $slot->isAvailable();
            });

            if ($available->isEmpty()) {
                continue;
            }

            $day = \Carbon\Carbon::parse((string) $date);

            $result[] = [
                'date' => $day->toDateString(),
                'label' => $day->format('d.m'),
                'weekday' => static::weekday($day->dayOfWeekIso),
                'slots' => $available->map(function (Slot $slot) {
                    return [
                        'time' => (string) $slot->time,
                        'available' => true,
                        'left' => max(0, (int) $slot->capacity - (int) $slot->taken),
                    ];
                })->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * occupied — карта занятости для бейджей «осталось N мест».
     *
     * @return array<string, int>
     */
    public static function occupied(string $serviceCode, int $days = 14): array
    {
        $from = now()->toDateString();
        $to = now()->addDays(max(1, $days))->toDateString();

        $map = [];

        foreach (Slot::query()->where('service_code', $serviceCode)->whereBetween('date', [$from, $to])->get() as $slot) {
            $map[$slot->date . ' ' . $slot->time] = max(0, (int) $slot->capacity - (int) $slot->taken);
        }

        return $map;
    }

    /**
     * generate — идемпотентно создаёт слоты для услуги.
     */
    public static function generate(string $serviceCode, int $days = 21, array $times = ['10:00', '12:00', '14:00', '16:00'], int $capacity = 1): int
    {
        $created = 0;
        $start = now()->addDay()->startOfDay();

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);

            if ($date->dayOfWeekIso >= 6) {
                continue;
            }

            foreach ($times as $time) {
                $slot = Slot::query()->firstOrCreate(
                    ['service_code' => $serviceCode, 'date' => $date->toDateString(), 'time' => $time],
                    ['capacity' => $capacity, 'taken' => 0]
                );

                if ($slot->wasRecentlyCreated) {
                    $created++;
                }
            }
        }

        return $created;
    }

    /**
     * prune — убирает прошедшие слоты без броней.
     */
    public static function prune(): int
    {
        return Slot::query()
            ->where('date', '<', now()->toDateString())
            ->where('taken', 0)
            ->delete();
    }

    /**
     * pruneOrphans — убирает свободные слоты услуг, которых больше нет в настройках.
     *
     * При смене пресета меняются коды услуг: старые слоты остаются в базе и
     * никем не показываются. Брони (`taken > 0`) не трогаем — на них ссылаются
     * заявки, и удалять чужие записи из-за демо-команды нельзя.
     */
    public static function pruneOrphans(): int
    {
        $codes = array_values(array_filter(array_column(static::services(), 'code')));
        $query = Slot::query()->where('taken', 0);

        if ($codes !== []) {
            $query->whereNotIn('service_code', $codes);
        }

        return $query->delete();
    }

    protected static function weekday(int $iso): string
    {
        return [
            1 => 'Пн',
            2 => 'Вт',
            3 => 'Ср',
            4 => 'Чт',
            5 => 'Пт',
            6 => 'Сб',
            7 => 'Вс',
        ][$iso] ?? '';
    }
}
