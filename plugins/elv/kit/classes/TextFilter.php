<?php namespace Elv\Kit\Classes;

/**
 * TextFilter — фильтр грубых слов в сообщениях консультанта.
 *
 * Ловит основы слов, а не конкретные формы: между буквами допускаются пробелы
 * и знаки (`х у й`), повтор букв (`пиззда`), латинские похожие буквы (`xyй`),
 * цифры вместо букв (`п1зд`, `3бал`) и замена буквы знаком (`х*й`). Слово, где
 * букву просто выбросили (`хй`), фильтр не разгадывает — иначе подстановка
 * любых символов ловит обычные слова. Основа ищется с начала слова, поэтому
 * «пидр» не находится в «эпидраздел».
 * Ответ на грубость вежливый, без повтора самого слова.
 */
class TextFilter
{
    public const REFUSAL = 'Давайте без грубостей — отвечу по делу. Спросите про тарифы, разделы сайта, установку, оплату или ключи.';

    /** @var array<string, string> */
    protected const LOOKALIKE = [
        'a' => 'а', 'b' => 'в', 'c' => 'с', 'e' => 'е', 'h' => 'н', 'k' => 'к',
        'm' => 'м', 'o' => 'о', 'p' => 'р', 't' => 'т', 'x' => 'х', 'y' => 'у',
        '0' => 'о', '1' => 'и', '3' => 'е', '4' => 'ч',
    ];

    /** @var array<string, string> */
    protected const REVERSE = [
        'а' => 'a', 'в' => 'b', 'с' => 'c', 'е' => 'e', 'н' => 'h', 'к' => 'k',
        'м' => 'm', 'о' => 'o', 'р' => 'p', 'т' => 't', 'х' => 'x', 'у' => 'y',
    ];

    /** @var array<string, string> */
    protected static $compiled = [];

    public static function has(string $text): bool
    {
        return static::match($text) !== null;
    }

    public static function match(string $text): ?string
    {
        $text = mb_strtolower($text);
        $text = str_replace('ё', 'е', $text);

        if (trim($text) === '') {
            return null;
        }

        $latin = strtr($text, static::REVERSE);

        foreach (static::latinWords() as $stem) {
            if (preg_match(static::pattern($stem, true, true), $latin)) {
                return $stem;
            }
        }

        $cyrillic = strtr($text, static::LOOKALIKE);

        foreach (static::cyrillicWords() as $stem) {
            if (preg_match(static::pattern($stem, true, false), $cyrillic)) {
                return $stem;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    protected static function cyrillicWords(): array
    {
        return [
            'хуй', 'хуя', 'хую', 'хуе', 'хуи',
            'пизд',
            'бляд', 'блят',
            'ебат', 'ебал', 'ебан', 'ебет', 'ебуч',
            'заеб', 'наеб', 'уеб', 'отъеб', 'выеб', 'доеб', 'проеб', 'съеб',
            'мудак', 'мудил',
            'пидор', 'пидар', 'пидр',
            'гандон', 'гондон',
            'шлюх',
            'долбоеб',
            'залуп',
            'дроч',
            'мразь', 'мрази',
            'гнида',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function latinWords(): array
    {
        return [
            'fuck', 'fucking', 'fucked', 'fucker', 'fuckers', 'fuckface',
            'shit', 'shits', 'shitty', 'bullshit',
            'bitch', 'bitches', 'bitching',
            'cunt', 'cunts',
            'asshole', 'assholes',
            'bastard', 'bastards',
            'dick', 'dicks', 'dickhead',
            'pussy', 'pussies',
            'whore', 'whores',
            'slut', 'sluts', 'slutty',
        ];
    }

    /**
     * pattern — буквы основы через любые пробелы и знаки, повторы букв разрешены.
     *
     * $start — совпадение только с начала слова: «пидр» не находится в «эпидраздел».
     * $end — совпадение только целым словом (для латиницы): «dick» не в «dickens».
     */
    protected static function pattern(string $stem, bool $start = false, bool $end = false): string
    {
        $key = $stem . ($start ? ':s' : '') . ($end ? ':e' : '');

        if (isset(static::$compiled[$key])) {
            return static::$compiled[$key];
        }

        $letters = preg_split('//u', $stem, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = [];
        $last = count($letters) - 1;

        foreach ($letters as $index => $letter) {
            $quoted = preg_quote($letter, '/');

            if ($index === 0 || $index === $last) {
                $parts[] = $quoted . '+';
                continue;
            }

            $parts[] = '(?:' . $quoted . '+|[\p{P}\p{S}]+)';
        }

        $prefix = $start ? '(?<![\p{L}\p{N}])' : '';
        $suffix = $end ? '(?![\p{L}\p{N}])' : '';

        return static::$compiled[$key] = '/' . $prefix . implode('[\s\p{P}\p{S}]*', $parts) . $suffix . '/u';
    }
}
