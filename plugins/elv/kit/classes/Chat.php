<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * Chat — консультант по базе знаний сайта.
 *
 * Отвечает без внешних сервисов и ключей: тарифы, модули, установка и оплата
 * берутся из каталога и настроек, поэтому ответ не расходится с витриной.
 */
class Chat
{
    public const MAX_LENGTH = 500;

    /**
     * answer — разбирает вопрос и возвращает ответ для виджета.
     */
    public static function answer(string $message): array
    {
        if (TextFilter::has($message)) {
            return static::reply(
                TextFilter::REFUSAL,
                ['Сколько стоит Elv Kit?', 'Какие модули есть?', 'Как установить?']
            );
        }

        $text = static::normalize($message);

        if (mb_strlen($text) < 2) {
            return static::reply(
                'Напишите вопрос подробнее — например: «сколько стоит», «как установить», «какие модули».',
                ['Сколько стоит Elv Kit?', 'Как установить?', 'Какие модули есть?']
            );
        }

        if (preg_match('/^(привет|здравствуй|добрый|доброе|хай|hello|hi)\b/u', $text)) {
            return static::reply(
                'Здравствуйте! Подскажу по тарифам, модулям, установке, оплате и лицензиям Elv Kit. Что интересует?',
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
                'Возврат обсуждаем индивидуально: если код не использован и пакет не скачан, вернём оплату. Условия — в оферте, запрос — через форму обратной связи.',
                ['Написать нам', 'Сколько стоит?'],
                null,
                'feedback'
            );
        }

        if (static::has($text, ['лиценз', 'домен', 'активац', 'ключ'])) {
            return static::reply(
                "Лицензия привязана к домену: Single Site — 1 домен, Multi-Site — до 5, Extended — без ограничений.\n"
                . "Ключ приходит после оплаты, активация — POST /api/v1/license/activate с ключом и доменом.",
                ['Сколько стоит?', 'Как оплатить?', 'Написать нам']
            );
        }

        if (static::has($text, ['скачать', 'доставк', 'zip', 'архив', 'токен', 'ссылк'])) {
            return static::reply(
                'После оплаты на e-mail приходит ссылка на скачивание ZIP. Архив собирается из актуальных файлов темы и плагина, ссылка открывается по токену заказа.',
                ['Как оплатить?', 'Что входит в Kit?', 'Написать нам']
            );
        }

        if (static::has($text, ['установ', 'постав', 'разверн', 'хостинг', 'требован', 'php', 'mysql', 'миграц', 'cpanel', 'laragon', 'composer'])) {
            return static::reply(
                "Требования: October CMS 4.4+, PHP 8.3+, MySQL 8.0+, cron и запись в storage.\n"
                . "Установка: тема — в themes/elv-studio, плагин — в plugins/elv/kit, затем php artisan october:migrate и php artisan elv:kit:demo. Настройки — в админке: Настройки → Elv Kit.",
                ['Сколько стоит?', 'Как оплатить?', 'Написать нам']
            );
        }

        if (static::has($text, ['оплат', 'платеж', 'stripe', 'юкасс', 'карт', 'счет', 'счёт'])) {
            return static::reply(
                'Оплата картой через ЮKassa или Stripe Checkout. В демо-режиме оплата имитируется, заказ помечается mock, а файл доступен по ссылке из письма.',
                ['Сколько стоит?', 'Как скачать?', 'Написать нам']
            );
        }

        if (static::has($text, ['брон', 'слот', 'запис', 'консультац', 'услуг', 'аудит'])) {
            return static::reply(
                'Модуль Booking ведёт слоты по услугам и защищает от двойной брони через UNIQUE(service_code, date, time) и lockForUpdate.' . static::servicesText(),
                ['Сколько стоит Booking?', 'Что входит в Kit?', 'Написать нам'],
                static::link('Тарифы и модули', '#pricing')
            );
        }

        if (static::has($text, ['заказ', 'магазин', 'корзин', 'покупк', 'купить', 'shop'])) {
            return static::reply(
                'Модуль Shop оформляет заказ на товар и тариф, принимает оплату и выдаёт ZIP по токену. Заказы и статусы видны в админке.',
                ['Сколько стоит Shop?', 'Как оплатить?', 'Какие модули есть?'],
                static::link('Тарифы', '#pricing')
            );
        }

        if (static::has($text, ['блог', 'стат', 'новост', 'rss'])) {
            return static::reply(
                'Блог входит в Elv Kit: статьи, категории, теги, RSS и sitemap. Тексты правятся в админке, SEO-поля — у каждой статьи.',
                ['Что входит в Kit?', 'Сколько стоит?', 'Написать нам'],
                static::link('Блог на сайте', '#blog')
            );
        }

        if (static::has($text, ['обновлен', 'поддержк', 'гаранти', 'документац', 'док'])) {
            return static::reply(
                'Обновления: 12 месяцев на Single и Multi, пожизненно на Extended. Поддержка: 30, 90 и 365 дней соответственно. Обновление — замена файлов темы и плагина, база не пересоздаётся.',
                ['Сколько стоит?', 'Как установить?', 'Написать нам']
            );
        }

        if (static::has($text, ['демо', 'посмотреть', 'потрогать', 'стенд', 'показать'])) {
            $demo = (string) Settings::get('demo_url');

            return static::reply(
                'Демо-стенд: админка, каталог, блог и бронирование на живом October CMS.'
                . ($demo !== '' ? ' Открыть: ' . $demo : ' Ссылку пришлём в ответ на заявку.'),
                ['Сколько стоит?', 'Что входит в Kit?'],
                $demo !== '' && str_starts_with($demo, 'http') ? ['label' => 'Открыть демо', 'url' => $demo] : null
            );
        }

        if (static::has($text, ['seo', 'сио', 'метрик', 'sitemap', 'llms', 'индекс', 'google', 'яндекс'])) {
            return static::reply(
                'SEO-контур: canonical, Open Graph, JSON-LD (Organization, SoftwareApplication, FAQPage, BlogPosting), sitemap.xml, robots.txt, llms.txt и RSS.',
                ['Что входит в Kit?', 'Сколько стоит?', 'Написать нам']
            );
        }

        if (static::has($text, ['цена', 'стоим', 'сколько', 'тариф', 'прайс', 'бюджет', 'дорого', 'price'])) {
            if (static::has($text, ['booking', 'shop', 'feedback', 'блог', 'blog'])) {
                return static::reply(static::modulePriceText($text), ['Что входит в Kit?', 'Какие модули есть?', 'Как оплатить?'], static::link('Модули', '#modules'));
            }

            return static::reply(static::plansText(), ['Что входит в Kit?', 'Какие модули есть?', 'Как оплатить?'], static::link('Сравнить тарифы', '#pricing'));
        }

        if (static::has($text, ['модул', 'плагин', 'booking', 'shop', 'feedback'])) {
            return static::reply(static::modulesText(), ['Сколько стоит?', 'Что входит в Kit?', 'Написать нам'], static::link('Модули', '#modules'));
        }

        if (static::has($text, ['входит', 'включ', 'состав', 'получ', 'функци', 'возможност'])) {
            return static::reply(
                "В Elv Kit входит:\n- " . implode("\n- ", Catalog::includes()),
                ['Сколько стоит?', 'Какие модули есть?', 'Как установить?'],
                static::link('Что входит', '#features')
            );
        }

        if (static::has($text, ['контакт', 'почта', 'email', 'telegram', 'телефон', 'whatsapp', 'написать'])) {
            return static::reply(static::contactsText(), ['Сколько стоит?', 'Как установить?'], null, 'feedback');
        }

        return static::reply(
            'Не нашёл ответа на этот вопрос. Уточните формулировку — или напишите нам, ответим на e-mail в течение рабочего дня.',
            ['Сколько стоит?', 'Что входит в Kit?', 'Как установить?', 'Какие модули есть?'],
            null,
            'feedback'
        );
    }

    protected static function reply(string $answer, array $chips = [], ?array $link = null, ?string $open = null): array
    {
        return [
            'answer' => $answer,
            'chips' => array_values(array_filter($chips)),
            'link' => $link,
            'open' => $open,
        ];
    }

    protected static function link(string $label, string $anchor): array
    {
        return ['label' => $label, 'url' => url('/') . $anchor];
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

    protected static function plansText(): string
    {
        $plans = Catalog::plans();

        if (!$plans) {
            return 'Каталог пока не заполнен. Запустите php artisan elv:kit:demo или напишите нам.';
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

        if (!count($modules)) {
            return 'Модули: Booking, Shop, Feedback продаются отдельно, блог входит в Elv Kit.';
        }

        $notes = [
            'booking' => 'слоты, защита от двойной брони',
            'shop' => 'заказы, оплата, выдача ZIP по токену',
            'feedback' => 'форма и виджет обратной связи',
            'blog' => 'статьи, категории, RSS, sitemap',
        ];

        $lines = ['Модули продаются отдельно:'];

        foreach ($modules as $module) {
            $note = $notes[$module->slug] ?? '';
            $lines[] = '• ' . $module->name . ' — ' . $module->price_label . ($note !== '' ? ': ' . $note : '');
        }

        $lines[] = 'Блог входит в Elv Kit.';

        return implode("\n", $lines);
    }

    protected static function modulePriceText(string $text): string
    {
        $slug = null;

        foreach (['booking' => 'booking', 'shop' => 'shop', 'feedback' => 'feedback', 'блог' => 'blog', 'blog' => 'blog'] as $word => $key) {
            if (static::has($text, [$word])) {
                $slug = $key;
                break;
            }
        }

        if ($slug === null) {
            return static::modulesText();
        }

        foreach (Catalog::modules() as $module) {
            if ($module->slug === $slug) {
                return $module->name . ' — ' . $module->price_label . '. Продаётся отдельно от Elv Kit и ставится как обычный плагин October.';
            }
        }

        return static::modulesText();
    }

    protected static function servicesText(): string
    {
        $services = Slots::services();

        if (!count($services)) {
            return '';
        }

        $lines = ["\nВ демо-бронировании:"];

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
