<?php namespace Elv\Kit\Models;

use Model;

/**
 * Booking — бронь слота.
 */
class Booking extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public const STATUS_NEW = 'new';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    public $table = 'elv_kit_bookings';

    public $fillable = [
        'service_code', 'service', 'price', 'date', 'time', 'person',
        'phone', 'email', 'comment', 'status', 'source', 'ip',
    ];

    protected $casts = [
        'reminded_at' => 'datetime',
        'admin_reminded_at' => 'datetime',
    ];

    public $rules = [
        'service_code' => 'required|max:64',
        'date' => 'required|date',
        'time' => 'required|max:8',
        'person' => 'required|max:160',
        'email' => 'nullable|email|max:191',
        'phone' => 'nullable|max:32',
    ];

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_CONFIRMED => 'Подтверждена',
            self::STATUS_CANCELLED => 'Отменена',
            default => 'Новая',
        };
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
