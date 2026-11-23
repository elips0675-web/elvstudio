<?php namespace Elv\Kit\Classes;

/**
 * Community — общие правила публичных вопросов и отзывов.
 *
 * Имя покупателя не публикуется целиком: на витрине остаётся имя и первая
 * буква фамилии («Алексей К.»). E-mail и IP хранятся только хэшем.
 */
class Community
{
    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', strip_tags($name)) ?? '');

        if ($name === '') {
            return 'Покупатель';
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = (string) ($parts[0] ?? '');

        if ($first === '') {
            return 'Покупатель';
        }

        $last = count($parts) > 1 ? (string) end($parts) : '';
        $first = mb_substr($first, 0, 60);

        if ($last === '') {
            return $first;
        }

        return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
    }

    public static function emailHash(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public static function ipHash(string $ip): string
    {
        return hash('sha256', trim($ip) . '|' . (string) config('app.key'));
    }

    public static function average(iterable $ratings): float
    {
        $sum = 0;
        $count = 0;

        foreach ($ratings as $rating) {
            $sum += (int) $rating;
            $count++;
        }

        return $count > 0 ? round($sum / $count, 1) : 0.0;
    }
}
