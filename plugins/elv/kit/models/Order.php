<?php namespace Elv\Kit\Models;

use Model;

/**
 * Order — заказ цифрового товара.
 * Деньги в центах, статус меняется только сервисом OrderService.
 */
class Order extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public const STATUS_NEW = 'new';
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REFUNDED = 'refunded';

    public $table = 'elv_kit_orders';

    public $rules = [
        'email' => 'required|email|max:191',
        'customer_name' => 'required|max:160',
        'amount_cents' => 'integer|min:0',
    ];

    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public $belongsTo = [
        'product' => [Product::class, 'key' => 'product_id'],
    ];

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Ожидает оплаты',
            self::STATUS_PAID => 'Оплачен',
            self::STATUS_CANCELLED => 'Отменён',
            self::STATUS_REFUNDED => 'Возврат',
            default => 'Новый',
        };
    }

    public function getPlanLabelAttribute(): string
    {
        return match ($this->plan) {
            'multi' => 'Multi-Site',
            'extended' => 'Extended',
            default => 'Single Site',
        };
    }

    public function getAmountAttribute(): float
    {
        return round(((int) $this->amount_cents) / 100, 2);
    }

    public function getAmountLabelAttribute(): string
    {
        return \Elv\Kit\Classes\Money::format((int) $this->amount_cents, (string) $this->currency);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function getDownloadUrlAttribute(): string
    {
        if (!$this->download_token) {
            return '';
        }

        return url('/download/' . $this->download_token);
    }

    public static function nextNumber(): string
    {
        return 'KIT-' . now()->format('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    }
}
