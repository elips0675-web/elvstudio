<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\Catalog;
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

    public $fillable = [
        'order_no', 'product_id', 'product_name', 'plan', 'qty', 'amount_cents',
        'currency', 'status', 'customer_name', 'email', 'phone', 'company',
        'comment', 'payment_provider', 'payment_id', 'download_token',
        'paid_at', 'delivered_at', 'ip', 'meta',
    ];

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
        $stored = (string) ($this->meta['plan_label'] ?? '');

        return $stored !== '' ? $stored : Catalog::planLabel((string) $this->plan);
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

    /**
     * consentLabel — юридический след согласия для карточки заказа.
     *
     * Показывает, когда покупатель принял оферту, с какого адреса и какая
     * редакция текста действовала: это ответ на чарджбэк «я ничего не принимал».
     */
    public function getConsentLabelAttribute(): string
    {
        $meta = (array) $this->meta;

        if (empty($meta['consent_accepted'])) {
            return 'Согласие не зафиксировано.';
        }

        $at = (string) ($meta['consent_at'] ?? '');
        $stamp = $at !== '' ? (string) \Carbon\Carbon::parse($at)->format('d.m.Y H:i') : 'время не сохранено';
        $ip = (string) ($meta['consent_ip'] ?? '') ?: 'IP не сохранён';
        $ua = (string) ($meta['consent_ua'] ?? '');
        $ua = $ua !== '' ? mb_substr($ua, 0, 120) : 'браузер не сохранён';
        $version = (string) ($meta['terms_version'] ?? '') ?: 'версия не сохранена';
        $url = (string) ($meta['terms_url'] ?? '');

        $line = $stamp . ' · ' . $ip . ' · оферта ' . $version . ' · ' . $ua;

        return $url !== '' ? $line . ' · ' . $url : $line;
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
