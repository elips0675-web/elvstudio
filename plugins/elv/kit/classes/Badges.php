<?php namespace Elv\Kit\Classes;

/**
 * Badges — подписи статусов продукта и модулей в одном месте.
 *
 * В шаблонах остаются ключи: `{{ elv_badge('included') }}`. Меняется подпись
 * здесь — и она меняется на всех страницах сразу.
 */
class Badges
{
    public const LABELS = [
        'featured' => 'Выбирают чаще',
        'primary' => 'Основной продукт',
        'wip' => 'В разработке',
        'separate' => 'Можно отдельно',
        'included' => 'Входит в Elv Kit',
    ];

    public static function label(string $key): string
    {
        return static::LABELS[$key] ?? '';
    }
}
