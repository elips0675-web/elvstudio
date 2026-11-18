<?php namespace Elv\Kit\Models;

use BackendAuth;
use Elv\Kit\Classes\Money;
use Model;

/**
 * Price — версия цены тарифа Elv Kit.
 *
 * Строка неизменяема по смыслу: новая цена — новая версия с новой датой
 * начала действия. Старая версия закрывается полем valid_to или флагом
 * is_active. Действующую версию выбирает Classes\Prices.
 */
class Price extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public const PLANS = [
        'single' => 'Один сайт',
        'multi' => 'До 5 сайтов',
        'extended' => 'Без ограничений',
    ];

    public $table = 'elv_kit_price_versions';

    public $fillable = [
        'product_id',
        'product_slug',
        'plan',
        'price_cents',
        'currency',
        'valid_from',
        'valid_to',
        'is_active',
        'note',
    ];

    public $rules = [
        'plan' => 'required|in:single,multi,extended',
        'price_cents' => 'required|integer|min:0',
        'valid_from' => 'required|date',
        'valid_to' => 'nullable|date|after_or_equal:valid_from',
        'product_slug' => 'required|max:160',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function beforeValidate()
    {
        if (!$this->product_slug) {
            $kit = Product::kit();

            $this->product_slug = $kit ? (string) $kit->slug : 'elv-kit';
            $this->product_id = $kit ? $kit->id : null;
        }

        if (!$this->currency) {
            $this->currency = Money::currency();
        }

        if (!$this->created_by && ($user = BackendAuth::getUser())) {
            $this->created_by = (int) $user->id;
        }
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getPlanLabelAttribute(): string
    {
        return static::PLANS[$this->plan] ?? (string) $this->plan;
    }

    public function getPriceLabelAttribute(): string
    {
        return Money::format((int) $this->price_cents, (string) ($this->currency ?: null));
    }

    public function getPeriodLabelAttribute(): string
    {
        $from = $this->valid_from ? $this->valid_from->format('d.m.Y') : '—';
        $to = $this->valid_to ? ' — ' . $this->valid_to->format('d.m.Y') : ' — бессрочно';

        return $from . $to;
    }

    public function getValidFromLabelAttribute(): string
    {
        return $this->valid_from ? $this->valid_from->format('d.m.Y') : '—';
    }

    public function getValidToLabelAttribute(): string
    {
        return $this->valid_to ? $this->valid_to->format('d.m.Y') : 'бессрочно';
    }
}
