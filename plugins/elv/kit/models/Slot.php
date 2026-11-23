<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\Slots;
use Illuminate\Validation\ValidationException;
use Model;

/**
 * Slot — слот услуги. Уникальность (service_code, date, time) держит БД.
 *
 * Слоты правит админка «Записи → Слоты»: дата, время, число мест. Занятый
 * слот удалить нельзя — сначала отменяется или переносится бронь.
 */
class Slot extends Model
{
    public $table = 'elv_kit_slots';

    public $timestamps = true;

    public $fillable = [
        'service_code',
        'date',
        'time',
        'capacity',
        'taken',
    ];

    public function isAvailable(): bool
    {
        return (int) $this->taken < (int) $this->capacity;
    }

    /**
     * getServiceCodeOptions — коды услуг из настроек, подпись для выпадающего списка.
     *
     * @return array<string, string>
     */
    public function getServiceCodeOptions(): array
    {
        $options = [];

        foreach (Slots::services() as $service) {
            $code = (string) ($service['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $options[$code] = (string) ($service['name'] ?? $code) . ' — ' . $code;
        }

        return $options;
    }

    public function beforeSave()
    {
        $this->service_code = trim((string) $this->service_code);

        if ($this->service_code === '') {
            throw ValidationException::withMessages([
                'service_code' => 'Выберите услугу из настроек записи.',
            ]);
        }

        $date = $this->date;

        if ($date instanceof \DateTimeInterface) {
            $date = $date->format('Y-m-d');
        }

        $date = trim((string) $date);
        $parsed = $date !== '' ? strtotime($date) : false;

        if ($parsed === false) {
            throw ValidationException::withMessages([
                'date' => 'Укажите дату слота.',
            ]);
        }

        $this->date = date('Y-m-d', $parsed);

        $time = trim((string) $this->time);

        if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $time, $matches) !== 1) {
            throw ValidationException::withMessages([
                'time' => 'Время в формате ЧЧ:ММ, например 10:00.',
            ]);
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            throw ValidationException::withMessages([
                'time' => 'Время в формате ЧЧ:ММ, например 10:00.',
            ]);
        }

        $this->time = sprintf('%02d:%02d', $hour, $minute);

        $capacity = (int) $this->capacity;
        $taken = (int) $this->taken;

        if ($capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => 'В слоте должно быть хотя бы одно место.',
            ]);
        }

        if ($taken < 0) {
            throw ValidationException::withMessages([
                'taken' => 'Число занятых мест не может быть отрицательным.',
            ]);
        }

        if ($taken > $capacity) {
            throw ValidationException::withMessages([
                'taken' => 'Занятых мест больше, чем мест в слоте.',
            ]);
        }

        $duplicate = static::query()
            ->where('service_code', $this->service_code)
            ->where('date', $this->date)
            ->where('time', $this->time)
            ->where('id', '<>', (int) $this->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'time' => 'Такой слот уже есть: ' . $this->date . ' в ' . $this->time . '.',
            ]);
        }
    }

    public function beforeDelete()
    {
        if ((int) $this->taken > 0) {
            throw ValidationException::withMessages([
                'taken' => 'В слоте есть записи. Сначала отмените или перенесите брони.',
            ]);
        }
    }
}
