<?php namespace Elv\Kit\Models;

use Model;

/**
 * Slot — слот услуги. Уникальность (service_code, date, time) держит БД.
 */
class Slot extends Model
{
    public $table = 'elv_kit_slots';

    public $timestamps = true;

    public function isAvailable(): bool
    {
        return (int) $this->taken < (int) $this->capacity;
    }
}
