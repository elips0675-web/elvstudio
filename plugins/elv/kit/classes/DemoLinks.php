<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * DemoLinks — дополнительные пункты списка демо на странице /demo/.
 *
 * Хранятся в настройках (Продажи → Дополнительные демо), поэтому ссылку
 * на стенд можно поменять в админке, не трогая шаблон. Формат строки:
 * «Название | адрес демо | адрес страницы | подпись». Адрес демо может быть
 * внешним, адрес страницы и подпись необязательны.
 */
class DemoLinks
{
    public static function items(): array
    {
        $items = [];
        $raw = (string) Settings::get('demo_links');

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = array_map('trim', explode('|', $line, 4));
            $name = $parts[0] ?? '';
            $demo = $parts[1] ?? '';
            $page = $parts[2] ?? '';
            $note = $parts[3] ?? '';

            if ($name === '' || $demo === '') {
                continue;
            }

            $items[] = [
                'name' => $name,
                'demo_url' => static::href($demo),
                'page_url' => $page !== '' ? static::href($page) : '',
                'note' => $note,
            ];
        }

        return $items;
    }

    /**
     * Внутренний адрес получает слэш на конце — иначе первый переход
     * по ссылке отвечает 301.
     */
    protected static function href(string $url): string
    {
        if (preg_match('~^(?:https?:)?//~i', $url) || preg_match('~^(?:mailto|tel):~i', $url)) {
            return $url;
        }

        $href = url('/' . ltrim($url, '/'));

        if (!str_contains($url, '?') && !str_contains($url, '#') && !str_ends_with($href, '/')) {
            $href .= '/';
        }

        return $href;
    }
}
