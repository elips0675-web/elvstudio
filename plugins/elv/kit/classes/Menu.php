<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * Menu — раздел «Продукт» в шапке сайта.
 *
 * Список пунктов хранится в настройках, поэтому новый пункт добавляется
 * в админке без правки шаблона. Один пункт шапка показывает прямой ссылкой,
 * два и больше — выпадающим списком. Пустые настройки дают один пункт
 * «Elv Kit», чтобы шапка работала на чистой установке.
 */
class Menu
{
    public const DEFAULT_LABEL = 'Продукт';

    public static function label(): string
    {
        $label = trim((string) Settings::get('menu_products_label', static::DEFAULT_LABEL));

        return $label !== '' ? $label : static::DEFAULT_LABEL;
    }

    public static function products(): array
    {
        $items = static::parse((string) Settings::get('menu_products'));

        return $items !== [] ? $items : static::fallback();
    }

    public static function hasCurrent(): bool
    {
        foreach (static::products() as $item) {
            if (!empty($item['current'])) {
                return true;
            }
        }

        return false;
    }

    public static function fallback(): array
    {
        return [
            static::item('Elv Kit', '/kit/', ''),
        ];
    }

    /**
     * Разбирает строки «Название | адрес | подпись». Подпись необязательна,
     * строки с решётки считаются комментарием.
     */
    public static function parse(string $raw): array
    {
        $items = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('|', $line, 3);
            $name = trim($parts[0]);
            $url = trim($parts[1] ?? '');

            if ($name !== '' && $url !== '') {
                $items[] = static::item($name, $url, trim($parts[2] ?? ''));
            }
        }

        return $items;
    }

    protected static function item(string $name, string $url, string $note): array
    {
        $href = static::href($url);

        return [
            'name' => $name,
            'url' => $href,
            'note' => $note,
            'current' => static::isCurrent($href),
        ];
    }

    /**
     * Внутренний адрес получает слэш на конце: /kit/ вместо /kit, иначе
     * первый переход по пункту меню отвечает 301.
     */
    protected static function href(string $url): string
    {
        if (preg_match('~^(?:https?:)?//~i', $url) || preg_match('~^(?:mailto|tel):~i', $url)) {
            return $url;
        }

        $href = url('/' . ltrim($url, '/'));

        // October отдаёт адрес без слэша на конце, а каноническая форма — со слэшем.
        if (!str_contains($url, '?') && !str_contains($url, '#') && !str_ends_with($href, '/')) {
            $href .= '/';
        }

        return $href;
    }

    protected static function isCurrent(string $url): bool
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $current = trim((string) request()->path(), '/');

        return $path !== '' && $path === $current;
    }
}
