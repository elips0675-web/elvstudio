<?php namespace Elv\Kit\Models;

use Model;

/**
 * License — ключ активации модуля на домене покупателя.
 */
class License extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public $table = 'elv_kit_licenses';

    protected $casts = [
        'activated_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public $hasMany = [
        'installs' => [Install::class, 'key' => 'license_id'],
    ];

    public function getStatusLabelAttribute(): string
    {
        return $this->status === self::STATUS_REVOKED ? 'Отозвана' : 'Активна';
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * generateKey — читаемый ключ: ELV-XXXX-XXXX-XXXX-XXXX.
     */
    public static function generateKey(): string
    {
        $block = function () {
            return strtoupper(substr(str_replace(['0', 'O', '1', 'I'], ['2', 'P', '3', 'J'], bin2hex(random_bytes(4))), 0, 4));
        };

        return 'ELV-' . $block() . '-' . $block() . '-' . $block() . '-' . $block();
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
