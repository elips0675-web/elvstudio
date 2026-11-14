<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * Money — деньги в центах и подписи.
 */
class Money
{
    public static function currency(): string
    {
        return (string) (Settings::get('currency') ?: 'USD');
    }

    public static function symbol(?string $currency = null): string
    {
        return match ($currency ?: static::currency()) {
            'BYN' => 'BYN',
            'RUB' => '₽',
            default => '$',
        };
    }

    public static function format(int $cents, ?string $currency = null): string
    {
        $value = $cents / 100;
        $formatted = $value === floor($value) ? number_format($value, 0, ',', ' ') : number_format($value, 2, ',', ' ');

        $symbol = static::symbol($currency);

        return $symbol === '$' ? '$' . $formatted : $formatted . ' ' . $symbol;
    }

    public static function cents($value): int
    {
        return (int) round(((float) $value) * 100);
    }
}
