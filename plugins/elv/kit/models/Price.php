<?php namespace Elv\Kit\Models;

use BackendAuth;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\Money;
use Elv\Kit\Classes\Prices;
use Illuminate\Validation\ValidationException;
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

    /**
     * afterSave / afterDelete — базовая цена карточки товара следует за прайсом.
     *
     * Карточка хранит цену как резерв, когда версий нет; если версия есть,
     * поле должно совпадать с действующей, иначе админка и JSON-LD показывают
     * одно, а заказ считает другое. Историю это не трогает: меняется только
     * карточка товара.
     */
    public function afterSave()
    {
        $this->syncProductBase();
    }

    public function afterDelete()
    {
        $this->syncProductBase();
    }

    protected function syncProductBase(): void
    {
        Prices::flush();

        if (!$this->product_slug) {
            return;
        }

        $product = Product::query()->where('slug', (string) $this->product_slug)->first();

        if (!$product) {
            return;
        }

        $actual = Prices::current((string) $this->product_slug, (string) $this->plan);

        if ($actual === null) {
            return;
        }

        $column = match ((string) $this->plan) {
            'multi' => 'price_multi_cents',
            'extended' => 'price_extended_cents',
            default => 'price_cents',
        };

        if ((int) $product->getAttribute($column) !== $actual) {
            Product::query()->whereKey($product->getKey())->update([$column => $actual]);
        }
    }

    /**
     * beforeUpdate — история цен неизменяема.
     *
     * Менять можно только valid_to, is_active и note: новая цена оформляется
     * новой версией, иначе старые заказы и счета теряют опору.
     */
    public function beforeUpdate()
    {
        $locked = [
            'price_cents' => 'цена',
            'plan' => 'тариф',
            'product_slug' => 'продукт',
            'currency' => 'валюта',
            'valid_from' => 'дата начала',
        ];
        $changed = [];

        foreach ($locked as $field => $label) {
            if ($this->priceFieldChanged($field)) {
                $changed[] = $label;
            }
        }

        if ($changed !== []) {
            throw ValidationException::withMessages([
                'price_cents' => 'Версия цены неизменяема: ' . implode(', ', $changed) . '. Создайте новую версию с новой датой начала.',
            ]);
        }
    }

    protected function priceFieldChanged(string $field): bool
    {
        $original = $this->getOriginal($field);
        $current = $this->getAttribute($field);

        if ($field === 'valid_from') {
            $original = $original ? date('Y-m-d', strtotime((string) $original)) : null;
            $current = $current ? $current->format('Y-m-d') : null;
        }

        return (string) $original !== (string) $current;
    }

    public function getPlanLabelAttribute(): string
    {
        return Catalog::planLabel((string) $this->plan);
    }

    /**
     * getPlanOptions — подписи тарифов для выпадающего списка в админке.
     *
     * @return array<string, string>
     */
    public function getPlanOptions(): array
    {
        return Catalog::planLabels();
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
