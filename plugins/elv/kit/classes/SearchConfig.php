<?php namespace Elv\Kit\Classes;

/**
 * SearchConfig — словари поиска в одном месте.
 *
 * Один источник для серверного поиска (`Search`) и клиентского
 * (`themes/elv-business/assets/js/search.js`): стоп-слова, группы синонимов и
 * окончания. Страница `/search/` отдаёт их JSON-атрибутом, поэтому правка
 * словаря не требует второй правки в JavaScript.
 */
class SearchConfig
{
    /** @var array<int, string> */
    protected const STOP_WORDS = [
        'и', 'в', 'во', 'на', 'для', 'с', 'со', 'по', 'как', 'что', 'это', 'или', 'не',
        'из', 'у', 'о', 'об', 'от', 'до', 'за', 'под', 'над', 'при', 'про', 'без',
        'же', 'бы', 'ли', 'то', 'все', 'мы', 'вы', 'он', 'она', 'они', 'мне', 'нам',
    ];

    /** @var array<int, array<int, string>> */
    protected const SYNONYM_GROUPS = [
        ['оплата', 'оплаты', 'оплате', 'оплату', 'оплатой', 'оплатить', 'платеж', 'платежи', 'платить'],
        ['запись', 'записи', 'записью', 'записаться', 'бронирование', 'бронирования', 'бронь', 'брони'],
        ['цена', 'цены', 'цене', 'цену', 'ценой', 'стоимость', 'стоимости', 'стоимостью', 'тариф', 'тарифы', 'тарифам', 'тарифами'],
        ['сайт', 'сайты', 'сайта', 'сайту', 'сайте', 'сайтом', 'лендинг', 'лендинга'],
        ['установка', 'установки', 'установить', 'настройка', 'настройки', 'настроить'],
        ['поддержка', 'поддержки', 'поддержать', 'сопровождение', 'сопровождения'],
        ['аудит', 'аудита', 'проверка', 'проверки', 'проверить'],
        ['блог', 'блога', 'блоге', 'статья', 'статьи', 'статью', 'статей'],
        ['ключ', 'ключа', 'ключи', 'ключей', 'лицензия', 'лицензии'],
    ];

    /** @var array<int, string> */
    protected const ENDINGS = [
        'иями', 'ями', 'ами', 'ией', 'иям', 'ием', 'иях', 'ого', 'его', 'ому', 'ему', 'ыми', 'ими',
        'овать', 'евать',
        'ая', 'яя', 'ое', 'ее', 'ые', 'ие', 'ой', 'ей', 'ий', 'ый', 'ом', 'ем', 'ам', 'ям', 'ах', 'ях',
        'ов', 'ев', 'ью', 'ия', 'ию', 'ии', 'ую', 'юю', 'ить', 'ать', 'ять', 'еть',
        'а', 'я', 'о', 'е', 'ы', 'и', 'у', 'ю', 'ь',
    ];

    /**
     * @return array<int, string>
     */
    public static function stopWords(): array
    {
        return self::STOP_WORDS;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function synonymGroups(): array
    {
        return self::SYNONYM_GROUPS;
    }

    /**
     * @return array<int, string>
     */
    public static function endings(): array
    {
        return self::ENDINGS;
    }

    /**
     * json — конфиг для клиентского поиска на странице /search/.
     */
    public static function json(): string
    {
        $json = json_encode(
            [
                'stop' => self::STOP_WORDS,
                'synonyms' => self::SYNONYM_GROUPS,
                'endings' => self::ENDINGS,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json === false ? '{}' : $json;
    }
}
