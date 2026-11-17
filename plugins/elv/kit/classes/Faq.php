<?php namespace Elv\Kit\Classes;

/**
 * Faq — читает FAQ из config/<name>.php.
 */
class Faq
{
    public static function items(string $name = 'faq'): array
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: 'faq';
        $path = base_path('plugins/elv/kit/config/' . $name . '.php');

        if (!is_file($path)) {
            return [];
        }

        $items = require $path;

        return is_array($items) ? $items : [];
    }

    public static function post(string $slug): array
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: '';
        $all = static::items('post-faq');

        return $slug !== '' && isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
    }
}
