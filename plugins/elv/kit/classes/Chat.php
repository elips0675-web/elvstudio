<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * Chat — консультант по базе знаний сайта.
 *
 * Отвечает без внешних сервисов и ключей: тарифы, разделы, запуск и оплата
 * берутся из каталога и настроек, поэтому ответ не расходится с витриной.
 */
class Chat
{
    public const MAX_LENGTH = 500;

    /** @var array<int, string> */
    protected const KNOWN_WORDS = [
        'привет', 'здравствуйте', 'здравствуй', 'добрый', 'доброе', 'хай', 'hello', 'hi',
        'спасибо', 'благодарю', 'пожалуйста',
        'человек', 'менеджер', 'оператор', 'консультант',
        'связаться', 'написать', 'позвонить',
        'настроить', 'настройка', 'настройки', 'настрою',
        'краудфандинг', 'краудфандинга', 'краудфандинге', 'краудфандингом',
    ];

    /**
     * TECH_WORDS — техтермины и англицизмы, которые не опечатки.
     *
     * Латиница отсекается отдельно в `looksBroken()`, а этот список нужен,
     * чтобы слово считалось знакомым и вопрос не уходил в «Не понял запрос».
     *
     * @var array<int, string>
     */
    protected const TECH_WORDS = [
        'api', 'ajax', 'apache', 'backup', 'cache', 'cdn', 'cms', 'composer', 'cron',
        'css', 'docker', 'dns', 'ftp', 'git', 'google', 'hosting', 'html', 'http',
        'https', 'js', 'json', 'laravel', 'laragon', 'mysql', 'nginx', 'node', 'npm',
        'october', 'php', 'postgres', 'redis', 'seo', 'smtp', 'sql', 'sqlite', 'ssh',
        'ssl', 'stripe', 'telegram', 'tls', 'twig', 'url', 'vps', 'webhook', 'yandex',
        'yaml', 'markdown', 'cloudflare',
        'апи', 'бэкап', 'бэкенд', 'вебхук', 'вебхуки', 'вебхука', 'виджет', 'джсон',
        'домен', 'домена', 'домены', 'интеграция', 'кеш', 'кэш', 'лендинг', 'маркетинг',
        'модуль', 'плагин', 'редирект', 'сервер', 'скрипт', 'смтп', 'ссл', 'телеграм',
        'телеграмм', 'телеграма', 'трафик', 'фронтенд', 'хостинг', 'шаблон',
    ];

    /**
     * answer — разбирает вопрос и возвращает ответ для виджета.
     */
    public static function answer(string $message): array
    {
        if (TextFilter::has($message)) {
            return static::reply(
                TextFilter::REFUSAL,
                ['Сколько стоит Elv Kit?', 'Какие разделы есть?', 'Как установить?'],
                null,
                null,
                [],
                true,
                true
            );
        }

        $text = static::normalize($message);

        if (mb_strlen($text) < 2) {
            return static::reply(
                'Напишите вопрос подробнее — например: «сколько стоит», «как установить», «какие разделы».',
                ['Сколько стоит Elv Kit?', 'Как установить?', 'Какие разделы есть?'],
                null,
                null,
                [],
                true
            );
        }

        if (
            static::has($text, ['найд', 'поиск', 'ищи', 'искать', 'подбер', 'материал', 'почитать'])
            || static::hasWord($text, ['стать'])
        ) {
            $results = static::search($message);

            if ($results !== []) {
                return static::searchReply($results);
            }

            return static::reply(
                'По этому запросу на сайте ничего не нашёл. Попробуйте сформулировать короче или напишите нам — подскажем вручную.',
                ['Сколько стоит?', 'Какие разделы есть?', 'Написать нам'],
                null,
                'feedback'
            );
        }

        if (static::hasNonsenseWord($text)) {
            return static::nonsenseReply();
        }

        if (preg_match('/^(привет|здравствуй|добрый|доброе|хай|hello|hi)\b/u', $text)) {
            return static::reply(
                'Здравствуйте! Подскажу по тарифам, разделам сайта, запуску, оплате и ключам Elv Kit. Что интересует?',
                ['Сколько стоит Elv Kit?', 'Что входит в Kit?', 'Как установить?']
            );
        }

        if (static::has($text, ['спасибо', 'благодар', 'спс'])) {
            return static::reply(
                'Пожалуйста. Если нужен счёт или договор — напишите в форме обратной связи, ответим на e-mail.',
                ['Сколько стоит?', 'Как установить?', 'Написать нам'],
                null,
                'feedback'
            );
        }

        if (static::has($text, ['человек', 'менеджер', 'оператор', 'живой', 'позвонить', 'созвон', 'консультант человек'])) {
            return static::reply(
                'Я отвечаю по базе знаний сайта. Чтобы подключился человек, оставьте сообщение в форме обратной связи — ответим на e-mail в течение рабочего дня.',
                ['Сколько стоит?', 'Как установить?'],
                null,
                'feedback'
            );
        }

        if (static::has($text, ['обратн', 'жалоб', 'сообщени', 'форм'])) {
            return static::reply(
                'Форма обратной связи открывается кнопкой «Обратная связь» в меню виджета или в подвале сайта. Сообщение попадает в админку, копия уходит на e-mail.',
                ['Сколько стоит?', 'Как установить?'],
                null,
                'feedback'
            );
        }

        if (static::has($text, ['возврат', 'вернуть', 'деньги обратно'])) {
            return static::reply(
                'Возврат обсуждаем индивидуально: если файлы сайта не скачаны, вернём оплату. Условия — в оферте, запрос — через форму обратной связи.',
                ['Написать нам', 'Сколько стоит?'],
                null,
                'feedback'
            );
        }

        if (static::has($text, ['лиценз', 'домен', 'активац', 'ключ'])) {
            $limits = [];

            foreach (Catalog::planKeys() as $key) {
                $limit = Catalog::planDomainLimit($key);
                $limits[] = '«' . Catalog::planLabel($key) . '» — ' . match (true) {
                    $limit <= 1 => 'один домен',
                    $limit >= 1000 => 'любое число доменов',
                    default => 'до ' . $limit . ' доменов',
                };
            }

            return static::reply(
                ($limits !== [] ? 'Ключ привязан к домену: ' . implode(', ', $limits) . ".\n" : '')
                . 'Ключ приходит на e-mail после оплаты; в админке на своём домене есть поле для ключа.',
                ['Сколько стоит?', 'Как оплатить?', 'Написать нам']
            );
        }

        if (static::has($text, ['скач', 'доставк', 'zip', 'архив', 'токен'])) {
            return static::reply(
                'После оплаты на e-mail приходит ссылка на файлы сайта. Архив собирается из актуальной версии, ссылка действует, пока заказ существует.',
                ['Как оплатить?', 'Что входит в Kit?', 'Написать нам']
            );
        }

        if (static::has($text, ['настро']) && static::has($text, ['сайт', 'kit', 'хостинг', 'домен'])) {
            return static::reply(
                "Настройка сайта описана по шагам в разделе «Инструкция»: домен, почта, ключ и первый запуск.\n"
                . "Если не хочется разбираться, поможем с установкой и запуском.",
                ['Сколько стоит?', 'Как оплатить?', 'Написать нам']
            );
        }

        if (static::has($text, ['установ', 'постав', 'разверн', 'хостинг', 'требован', 'php', 'mysql', 'миграц', 'cpanel', 'laragon', 'composer'])) {
            return static::reply(
                "Нужен обычный хостинг с PHP и MySQL, доступ к панели управления и почта для писем.\n"
                . "Установка описана по шагам в разделе «Инструкция» — без команд. Если не хочется разбираться, установим сами.",
                ['Сколько стоит?', 'Как оплатить?', 'Написать нам']
            );
        }

        if (static::has($text, ['оплат', 'платеж', 'stripe', 'юкасс', 'карт', 'счет', 'счёт'])) {
            return static::reply(
                'Оплата картой через ЮKassa или Stripe на защищённой странице. В демо-режиме оплата имитируется, деньги не списываются.',
                ['Сколько стоит?', 'Как скачать?', 'Написать нам']
            );
        }

        if (static::has($text, ['цена', 'стоим', 'сколько', 'тариф', 'прайс', 'бюджет', 'дорого', 'price'])) {
            if (
                static::has($text, ['booking', 'shop', 'feedback', 'блог', 'blog', 'запис', 'заказ', 'обратн', 'магазин'])
                || static::hasConnection($text)
            ) {
                return static::reply(static::modulePriceText($text), ['Что входит в Kit?', 'Какие разделы есть?', 'Как оплатить?'], static::link('Разделы', '/modules/'));
            }

            return static::reply(static::plansText(), ['Что входит в Kit?', 'Какие разделы есть?', 'Как оплатить?'], static::link('Сравнить тарифы', '/pricing/'));
        }

        if (static::has($text, ['брон', 'слот', 'запис', 'консультац'])) {
            return static::reply(
                'Онлайн-запись: клиент выбирает услугу, дату и свободное время. Занятое время исчезает из списка, поэтому две записи на одно время не появятся.' . static::bookingServicesText(),
                ['Сколько стоит онлайн-запись?', 'Что входит в Kit?', 'Написать нам'],
                static::link('Цены', '/pricing/')
            );
        }

        if (static::has($text, ['аудит'])) {
            $services = static::servicesText();

            if ($services !== '' && mb_stripos($services, 'аудит') !== false) {
                return static::reply(
                    'Бесплатный аудит: проверяем скорость, SEO и безопасность сайта, показываем, что исправить в первую очередь.',
                    ['Сколько стоит?', 'Что входит в Kit?', 'Написать нам'],
                    static::servicesLink('Бесплатный аудит')
                );
            }

            return static::servicesReply();
        }

        if (static::has($text, ['услуг'])) {
            return static::servicesReply();
        }

        if (static::has($text, ['заказ', 'магазин', 'корзин', 'покупк', 'купить', 'shop'])) {
            return static::reply(
                'Раздел «Заказы и оплата»: покупатель оформляет заказ и платит картой, после оплаты получает файл. Заказы и статусы видны в админке.',
                ['Сколько стоит заказы и оплата?', 'Как оплатить?', 'Какие разделы есть?'],
                static::link('Цены', '/pricing/')
            );
        }

        if (static::has($text, ['блог', 'новост', 'rss']) || static::hasWord($text, ['стат'])) {
            return static::reply(
                'Блог входит в Elv Kit: статьи, категории, подписка на новости и карта сайта. Тексты правятся в админке, у каждой статьи есть заголовок и описание для поиска.',
                ['Что входит в Kit?', 'Сколько стоит?', 'Написать нам'],
                static::link('Блог на сайте', '/blog/')
            );
        }

        if (static::has($text, ['обновлен', 'поддержк', 'гаранти', 'документац', 'док'])) {
            $terms = [];

            foreach (Catalog::plans() as $plan) {
                $terms[] = '«' . $plan['label'] . '» — обновления ' . mb_strtolower((string) $plan['updates'])
                    . ', поддержка ' . mb_strtolower((string) $plan['support']);
            }

            return static::reply(
                ($terms !== [] ? 'По тарифам: ' . implode('; ', $terms) . '. ' : '')
                . 'Обновление заменяет файлы сайта, база и заказы остаются.',
                ['Сколько стоит?', 'Как установить?', 'Написать нам']
            );
        }

        if (static::has($text, ['демо', 'посмотреть', 'потрогать', 'стенд', 'показать'])) {
            $demo = (string) Settings::get('demo_url');

            return static::reply(
                'Демо — это работающий сайт: каталог, онлайн-запись, блог и админка.'
                . ($demo !== '' ? ' Открыть: ' . $demo : ' Ссылку пришлём в ответ на заявку.'),
                ['Сколько стоит?', 'Что входит в Kit?'],
                $demo !== '' && str_starts_with($demo, 'http') ? ['label' => 'Открыть демо', 'url' => $demo] : null
            );
        }

        if (static::has($text, ['seo', 'сио', 'метрик', 'sitemap', 'llms', 'индекс', 'google', 'яндекс'])) {
            return static::reply(
                'У каждой страницы есть заголовок и описание, сайт отдаёт поисковикам карту сайта и разметку для Google и Яндекса. Блог помогает собирать запросы по услугам.',
                ['Что входит в Kit?', 'Сколько стоит?', 'Написать нам'],
                static::link('Инструкция', '/docs/')
            );
        }

        if (static::has($text, ['модул', 'плагин', 'booking', 'shop', 'feedback', 'раздел'])) {
            return static::reply(static::modulesText(), ['Сколько стоит?', 'Что входит в Kit?', 'Написать нам'], static::link('Разделы', '/modules/'));
        }

        if (static::has($text, ['входит', 'включ', 'состав', 'получ', 'функци', 'возможност'])) {
            return static::reply(
                "В Elv Kit входит:\n- " . implode("\n- ", Catalog::includes()),
                ['Сколько стоит?', 'Какие разделы есть?', 'Как установить?'],
                static::link('Что входит', '/kit/')
            );
        }

        if (
            static::has($text, ['контакт', 'почта', 'email', 'telegram', 'телеграм', 'телефон', 'whatsapp', 'написать'])
            || static::hasConnection($text)
        ) {
            return static::reply(static::contactsText(), ['Сколько стоит?', 'Как установить?'], null, 'feedback');
        }

        $results = static::search($message);

        if ($results !== []) {
            return static::searchReply($results, true);
        }

        if (static::isNonsense($text)) {
            return static::nonsenseReply();
        }

        return static::reply(
            'Не нашёл ответа на этот вопрос. Уточните формулировку — или напишите нам, ответим на e-mail в течение рабочего дня.',
            ['Сколько стоит?', 'Что входит в Kit?', 'Как установить?', 'Какие разделы есть?'],
            null,
            'feedback'
        );
    }

    protected static function reply(string $answer, array $chips = [], ?array $link = null, ?string $open = null, array $sources = [], bool $rejected = false, bool $hideUser = false): array
    {
        return [
            'answer' => $answer,
            'chips' => array_values(array_filter($chips)),
            'link' => $link,
            'open' => $open,
            'sources' => array_values($sources),
            'rejected' => $rejected,
            'hide_user' => $hideUser,
        ];
    }

    protected static function nonsenseReply(): array
    {
        return static::reply(
            'Не понял запрос. Напишите, что нужно: тарифы, установка, оплата, разделы сайта или ключи.',
            ['Сколько стоит?', 'Что входит в Kit?', 'Как установить?', 'Какие разделы есть?'],
            null,
            null,
            [],
            true
        );
    }

    /**
     * isNonsense — не пропускает текст без знакомых слов и наборы букв.
     */
    protected static function isNonsense(string $text): bool
    {
        $known = 0;
        $broken = false;

        foreach (static::meaningfulWords($text) as $word) {
            if (static::isKnownWord($word)) {
                $known++;
                continue;
            }

            if (static::looksBroken($word)) {
                $broken = true;
            }
        }

        return $known === 0 || $broken;
    }

    /**
     * hasNonsenseWord — отбивает бессмыслицу рядом со знакомым словом
     * («абырвалг оплата»), не дожидаясь запасного поиска.
     */
    protected static function hasNonsenseWord(string $text): bool
    {
        $known = 0;
        $broken = false;

        foreach (static::meaningfulWords($text) as $word) {
            if (static::isKnownWord($word)) {
                $known++;
                continue;
            }

            if (static::looksBroken($word)) {
                $broken = true;
            }
        }

        return $known > 0 && $broken;
    }

    /**
     * @return array<int, string>
     */
    protected static function meaningfulWords(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, function (string $word): bool {
            return mb_strlen($word) >= 3;
        }));
    }

    protected static function isKnownWord(string $word): bool
    {
        if (in_array($word, static::KNOWN_WORDS, true) || in_array($word, static::TECH_WORDS, true)) {
            return true;
        }

        return Search::query($word, Search::TYPE_ALL, 1) !== [];
    }

    protected static function looksBroken(string $word): bool
    {
        if (mb_strlen($word) < 4) {
            return false;
        }

        // Латиница — технический термин или название, а не русская опечатка.
        if (preg_match('/^[a-z0-9][a-z0-9._+-]*$/i', $word)) {
            return false;
        }

        if (!preg_match('/[аеёиоуыэюяaeiouy]/u', $word)) {
            return true;
        }

        if (preg_match('/(.)\1\1/u', $word)) {
            return true;
        }

        return mb_strlen($word) >= 5
            && !preg_match('/(а|я|о|е|ы|и|у|ю|ь|ый|ий|ая|ое|ие|ые|ость|ция|инг|изм|ист|ник|тель|ть|ться|ить|ать|ять|еть|ия|ил|ал|ел|ул|ол|ов|ев|ей)$/u', $word);
    }

    /**
     * search — подбирает страницы и статьи по вопросу пользователя.
     */
    protected static function search(string $message): array
    {
        return Search::query($message, Search::TYPE_ALL, 3);
    }

    /**
     * searchReply — отвечает ссылками на найденные материалы, не пересказывая их.
     */
    protected static function searchReply(array $items, bool $fallback = false): array
    {
        $sources = [];
        $seen = [];

        foreach ($items as $item) {
            $label = trim((string) ($item['title'] ?? ''));
            $path = trim((string) ($item['url'] ?? ''));

            if ($label === '' || $path === '' || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $sources[] = [
                'label' => $label,
                'url' => rtrim(url($path), '/') . '/',
                'kind' => ($item['type'] ?? '') === Search::TYPE_BLOG ? 'Статья' : 'Страница',
            ];
        }

        if ($sources === []) {
            return static::reply(
                'По этому запросу на сайте ничего не нашёл. Попробуйте сформулировать короче или напишите нам — подскажем вручную.',
                ['Сколько стоит?', 'Какие разделы есть?', 'Написать нам'],
                null,
                'feedback'
            );
        }

        return static::reply(
            ($fallback ? 'Точного ответа нет, но на сайте есть материалы по теме.' : 'Нашёл материалы по теме.') . ' Откройте нужную ссылку ниже или уточните вопрос.',
            ['Сколько стоит?', 'Что входит в Kit?', 'Написать нам'],
            null,
            null,
            $sources
        );
    }

    protected static function link(string $label, string $path): array
    {
        $url = url($path);

        if (str_ends_with($path, '/')) {
            $url = rtrim($url, '/') . '/';
        }

        return ['label' => $label, 'url' => $url];
    }

    protected static function normalize(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = str_replace('ё', 'е', $text);
        $text = (string) preg_replace('/[^\p{L}\p{Nd}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected static function has(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if ($word !== '' && mb_strpos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * hasWord — совпадение с начала слова, а не внутри другого.
     *
     * `has()` ищет подстроку: «стать» в «перестать» — не запрос про статьи.
     * Стем проверяется от начала слова, поэтому «статьи» и «статью» находятся,
     * а «остаться» и «перестать» — нет.
     *
     * @param array<int, string> $words
     * @param array<int, string> $except продолжения слова, которые не считаются совпадением
     */
    protected static function hasWord(string $text, array $words, array $except = []): bool
    {
        $tail = '';

        if ($except !== []) {
            $tail = '(?!' . implode('|', array_map(
                fn (string $part): string => preg_quote($part, '/'),
                $except
            )) . ')';
        }

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . $tail . '/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * hasConnection — вопрос про связь, а не про «связку» терминов.
     */
    protected static function hasConnection(string $text): bool
    {
        return static::hasWord($text, ['связ'], ['к']);
    }

    protected static function plansText(): string
    {
        $plans = Catalog::plans();

        if (!$plans) {
            return 'Каталог пока не заполнен. Напишите нам — пришлём тарифы.';
        }

        $lines = ['Тарифы Elv Kit:'];

        foreach ($plans as $plan) {
            $lines[] = '• ' . $plan['label'] . ' — ' . Money::format((int) $plan['price']) . ': ' . $plan['period'] . '.';
        }

        $lines[] = 'Оплата разовая, без подписки.';

        return implode("\n", $lines);
    }

    protected static function modulesText(): string
    {
        $modules = Catalog::modules();
        $notes = [
            'booking' => 'онлайн-запись, занятое время исчезает',
            'shop' => 'заказы, оплата и выдача файла',
            'feedback' => 'форма и виджет обратной связи',
            'blog' => 'статьи, категории и карта сайта',
        ];

        if (!count($modules)) {
            return 'Онлайн-запись, заказы и оплата, обратная связь и блог входят в Elv Kit на любом тарифе. Отдельно разделы не продаются.';
        }

        $lines = ['Разделы входят в Elv Kit на любом тарифе:'];

        foreach ($modules as $module) {
            $note = $notes[$module->slug] ?? '';
            $lines[] = '• ' . $module->name . ($note !== '' ? ' — ' . $note : '');
        }

        $lines[] = 'Отдельно разделы не продаются.';

        return implode("\n", $lines);
    }

    protected static function modulePriceText(string $text): string
    {
        $slug = null;

        foreach (['booking' => 'booking', 'shop' => 'shop', 'feedback' => 'feedback', 'блог' => 'blog', 'blog' => 'blog', 'запис' => 'booking', 'заказ' => 'shop', 'магазин' => 'shop', 'обратн' => 'feedback'] as $word => $key) {
            if (static::has($text, [$word])) {
                $slug = $key;
                break;
            }
        }

        if ($slug === null && static::hasConnection($text)) {
            $slug = 'feedback';
        }

        if ($slug === null) {
            return static::modulesText();
        }

        foreach (Catalog::modules() as $module) {
            if ($module->slug === $slug) {
                return $module->name . ' входит в Elv Kit на любом тарифе и отдельно не продаётся. ' . static::plansText();
            }
        }

        return static::modulesText();
    }

    protected static function servicesText(): string
    {
        $raw = (string) Settings::get('chat_services');
        $lines = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*[-•*]\s*/u', '', trim($line)));

            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode('; ', $lines);
    }

    protected static function servicesLink(string $label = 'Услуги сайта'): ?array
    {
        $url = trim((string) Settings::get('chat_services_url'));

        if ($url === '' || $url[0] !== '/' || strlen($url) > 200) {
            return null;
        }

        return static::link($label, $url);
    }

    protected static function servicesReply(): array
    {
        $services = static::servicesText();

        if ($services === '') {
            return static::reply(
                'Подробно об услугах и условиях работы расскажем по запросу — напишите нам через форму обратной связи.',
                ['Сколько стоит?', 'Написать нам']
            );
        }

        return static::reply(
            'Услуги сайта: ' . $services . '.',
            ['Сколько стоит?', 'Написать нам'],
            static::servicesLink()
        );
    }

    protected static function bookingServicesText(): string
    {
        $services = Slots::services();

        if (!count($services)) {
            return '';
        }

        $lines = ["\nУслуги для записи:"];

        foreach ($services as $service) {
            $lines[] = '• ' . $service['name'] . ' — ' . $service['duration'] . ' мин, ' . $service['price'] . '.';
        }

        return implode("\n", $lines);
    }

    protected static function contactsText(): string
    {
        $parts = [];

        $email = (string) Settings::get('email');
        $phone = (string) Settings::get('phone');
        $telegram = (string) Settings::get('telegram');

        if ($email !== '') {
            $parts[] = 'E-mail: ' . $email;
        }

        if ($phone !== '') {
            $parts[] = 'Телефон: ' . $phone;
        }

        if ($telegram !== '') {
            $parts[] = 'Telegram: ' . $telegram;
        }

        if (!$parts) {
            return 'Оставьте сообщение в форме обратной связи — ответим на e-mail.';
        }

        return implode("\n", $parts) . "\nИли оставьте сообщение в форме обратной связи.";
    }
}
