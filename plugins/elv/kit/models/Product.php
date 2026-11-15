<?php namespace Elv\Kit\Models;

use Model;

/**
 * Product — товар лендинга: Elv Kit, модуль или дополнение.
 */
class Product extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'elv_kit_products';

    public $rules = [
        'name' => 'required|max:160',
        'slug' => 'required|max:160|unique:elv_kit_products,slug',
        'price_cents' => 'integer|min:0',
    ];

    protected $casts = [
        'features' => 'array',
        'compatibility' => 'array',
        'changelog' => 'array',
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    public $attachOne = [];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeKind($query, string $kind)
    {
        return $query->where('kind', $kind)->orderBy('sort_order');
    }

    public function getUrlAttribute(): string
    {
        return $this->kind === 'kit'
            ? url('/kit/')
            : url('/modules/' . $this->slug . '/');
    }

    public function getPriceAttribute(): float
    {
        return round(((int) $this->price_cents) / 100, 2);
    }

    public function getPriceLabelAttribute(): string
    {
        return (int) $this->price_cents > 0
            ? \Elv\Kit\Classes\Money::format((int) $this->price_cents)
            : 'Бесплатно';
    }

    public function planPrice(string $plan): int
    {
        $map = [
            'single' => (int) $this->price_cents,
            'multi' => (int) ($this->price_multi_cents ?: $this->price_cents),
            'extended' => (int) ($this->price_extended_cents ?: $this->price_cents),
        ];

        return $map[$plan] ?? (int) $this->price_cents;
    }

    public function planLabel(string $plan): string
    {
        return match ($plan) {
            'multi' => 'До 5 сайтов',
            'extended' => 'Без ограничений',
            default => 'Один сайт',
        };
    }

    public static function kit(): ?self
    {
        return static::query()->where('kind', 'kit')->where('is_active', true)->first();
    }

    public static function modules()
    {
        return static::query()->where('kind', 'module')->where('is_active', true)->orderBy('sort_order')->get();
    }
}
