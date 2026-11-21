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
        return static::minifyCss(static::read($relative));
    }

    public static function js(string $relative): string
    {
        return static::minifyJs(static::read($relative));
    }

    public static function pluginCss(string $relative): string
    {
        return static::minifyCss(static::readPlugin($relative));
    }

    public static function pluginJs(string $relative): string
    {
        return static::minifyJs(static::readPlugin($relative));
    }

    public static function inlineCss(string $css): string
    {
        return trim($css);
    }

    /**
     * Сжатие CSS без разбора грамматики: комментарии, переносы и пробелы вокруг
     * служебных символов. Пробел перед `:` не трогаем — `a :hover` и `a:hover`
     * это разные селекторы. Пробел после `:` в объявлении не нужен, поэтому
     * `color: red` сжимается до `color:red` (строк с `content: "…: …"` в теме нет).
     */
    protected static function minifyCss(string $css): string
    {
        if ($css === '') {
            return '';
        }

        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace('~\s+~', ' ', $css);
        $css = (string) preg_replace('~\s*([{};,])\s*~', '$1', $css);
        $css = str_replace([';}', ': '], ['}', ':'], $css);

        return trim($css);
    }

    /**
     * Сжатие JS без перестановки токенов: убираем комментарии, отступы и пустые
     * строки. Переводы строк сохраняются, поэтому ASI не ломается.
     *
     * Разбор идёт по явным `\r\n|\r|\n`: `\R` без флага `u` считает переводом
     * строки байт 0x85, а это второй байт русской «х» — строка рвётся посередине.
     */
    protected static function minifyJs(string $js): string
    {
        if ($js === '') {
            return '';
        }

        $js = (string) preg_replace('~/\*.*?\*/~s', '', $js);
        $lines = preg_split('/\r\n|\r|\n/', $js) ?: [];

        $lines = array_map(static function ($line) {
            return trim($line);
        }, $lines);

        $lines = array_filter($lines, static function ($line) {
            return $line !== '';
        });

        return implode("\n", $lines);
    }

    protected static function readPlugin(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $key = 'plugin:' . $relative;

        if (isset(static::$cache[$key])) {
            return static::$cache[$key];
        }

        $path = dirname(__DIR__) . '/' . $relative;

        if (!is_file($path)) {
            return static::$cache[$key] = '';
        }

        return static::$cache[$key] = (string) file_get_contents($path);
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
