<?php namespace Elv\Kit\Classes;

use Cms\Classes\Theme;

/**
 * Assets — инлайн CSS и JS из темы.
 *
 * Отдельный файл на диске, но без отдельного запроса: содержимое вшивается
 * в HTML с nonce. Так работает CSP без `unsafe-inline`, а браузер не ждёт
 * второй ресурс до первой отрисовки.
 */
class Assets
{
    /** @var array<string, string> */
    protected static $cache = [];

    public static function css(string $relative): string
    {
        return static::read($relative);
    }

    public static function js(string $relative): string
    {
        return static::read($relative);
    }

    public static function inlineCss(string $css): string
    {
        return trim($css);
    }

    protected static function read(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if (isset(static::$cache[$relative])) {
            return static::$cache[$relative];
        }

        try {
            $theme = Theme::getActiveTheme();
            $path = $theme->getPath() . '/' . $relative;
        }
        catch (\Throwable $e) {
            return '';
        }

        if (!is_file($path)) {
            return static::$cache[$relative] = '';
        }

        return static::$cache[$relative] = (string) file_get_contents($path);
    }
}
