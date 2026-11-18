<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Price;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;
use Schema;

/**
 * Prices — действующая версия цены тарифа на дату.
 *
 * Версии лежат в elv_kit_price_versions; действует та, у которой
 * valid_from <= дата и (valid_to пусто или valid_to >= дата), при равенстве
 * дат начала побеждает более поздняя запись. Если версий нет, цена берётся
 * из карточки товара — тем же полем, по которому создаётся заказ.
 */
class Prices
{
    protected static $rows = null;

    public static function flush(): void
    {
        static::$rows = null;
    }

    /**
     * rows — все версии цен, отсортированные по дате начала.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function rows(): array
    {
        if (static::$rows !== null) {
            return static::$rows;
        }

        static::$rows = [];

        if (!Schema::hasTable('elv_kit_price_versions')) {
            return static::$rows;
        }

        foreach (Price::query()->orderBy('valid_from')->orderBy('id')->get() as $version) {
            static::$rows[] = [
                'product_slug' => (string) $version->product_slug,
                'plan' => (string) $version->plan,
                'price_cents' => (int) $version->price_cents,
                'valid_from' => $version->valid_from ? $version->valid_from->format('Y-m-d') : '',
                'valid_to' => $version->valid_to ? $version->valid_to->format('Y-m-d') : '',
                'is_active' => (bool) $version->is_active,
            ];
        }

        return static::$rows;
    }

    public static function current(string $productSlug, string $plan, ?string $onDate = null): ?int
    {
        $date = $onDate ?: date('Y-m-d');
        $found = null;

        foreach (static::rows() as $row) {
            if (!$row['is_active'] || $row['product_slug'] !== $productSlug || $row['plan'] !== $plan) {
                continue;
            }

            if ($row['valid_from'] === '' || $row['valid_from'] > $date) {
                continue;
            }

            if ($row['valid_to'] !== '' && $row['valid_to'] < $date) {
                continue;
            }

            if ($found === null || $row['valid_from'] >= $found['valid_from']) {
                $found = $row;
            }
        }

        return $found === null ? null : (int) $found['price_cents'];
    }

    /**
     * resolve — цена тарифа: версия прайса, иначе поле карточки товара.
     */
    public static function resolve(Product $product, string $plan, ?string $onDate = null): int
    {
        $current = static::current((string) $product->slug, $plan, $onDate);

        return $current === null ? $product->planPrice($plan) : $current;
    }

    public static function updatedAt(?string $productSlug = null): ?string
    {
        $productSlug = $productSlug ?: static::kitSlug();
        $date = null;

        foreach (static::rows() as $row) {
            if ($row['product_slug'] !== $productSlug || !$row['is_active']) {
                continue;
            }

            if ($row['valid_from'] === '') {
                continue;
            }

            if ($date === null || $row['valid_from'] > $date) {
                $date = $row['valid_from'];
            }
        }

        return $date ? date('d.m.Y', strtotime($date)) : null;
    }

    /**
     * label — дата, которую видит посетитель: прайс, затем поле настроек.
     */
    public static function label(): string
    {
        return static::updatedAt() ?: (string) (Settings::get('prices_updated_at') ?: '17.11.2026');
    }

    protected static function kitSlug(): string
    {
        $kit = Product::kit();

        return $kit ? (string) $kit->slug : 'elv-kit';
    }
}
