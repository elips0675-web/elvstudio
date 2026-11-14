<?php namespace Elv\Kit\Models;

use Model;

/**
 * Subscriber — подписка на статьи блога, double opt-in.
 */
class Subscriber extends Model
{
    public $table = 'elv_kit_subscribers';

    protected $casts = [
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
    ];

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null && $this->unsubscribed_at === null;
    }

    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
