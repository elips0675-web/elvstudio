<?php namespace Elv\Kit\Classes;

use Illuminate\Http\Request;

/**
 * AntiSpam — honeypot и нормализация полей.
 *
 * Капчу не ставим: она бьёт по конверсии и по PageSpeed. Honeypot-поле
 * скрыто от людей и заполняется ботами — такому запросу отвечаем «ок»,
 * но ничего не пишем в БД (иначе бот получает сигнал об успехе).
 */
class AntiSpam
{
    public const HONEYPOT = 'website_url';

    public static function isBot(Request $request): bool
    {
        return trim((string) $request->input(self::HONEYPOT, '')) !== '';
    }

    public static function clean($value, int $max = 5000): string
    {
        $value = strip_tags(trim((string) $value));
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;

        return mb_substr($value, 0, $max);
    }

    public static function ip(Request $request): string
    {
        return mb_substr((string) ($request->ip() ?: ''), 0, 45);
    }

    public static function json(array $payload, int $status = 200)
    {
        return response()->json($payload, $status, [], JSON_UNESCAPED_UNICODE);
    }
}

