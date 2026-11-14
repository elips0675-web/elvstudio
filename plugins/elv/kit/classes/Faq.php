<?php namespace Elv\Kit\Classes;

/**
 * Faq — список вопросов из config/faq.php.
 */
class Faq
{
    public static function items(): array
    {
        $path = base_path('plugins/elv/kit/config/faq.php');

        if (!is_file($path)) {
            return [];
        }

        $items = require $path;

        return is_array($items) ? $items : [];
    }
}
