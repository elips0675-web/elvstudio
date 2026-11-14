<?php namespace Elv\Kit\Classes;

use Illuminate\Support\Facades\DB;

/**
 * Demo — демо-данные Elv Kit в одном месте.
 *
 * Миграция и команда `elv:kit:demo` вызывают одни и те же методы: две копии
 * демо-каталога разъезжаются молча, и «сброс демо» перестаёт совпадать
 * с тем, что видит новый покупатель.
 */
class Demo
{
    /**
     * seed — идемпотентно создаёт категории, товары и статьи.
     *
     * @return array{categories: int, products: int, posts: int}
     */
    public static function seed(): array
    {
        $created = ['categories' => 0, 'products' => 0, 'posts' => 0];
        $now = now();

        foreach (static::categories() as $category) {
            $exists = DB::table('elv_kit_categories')->where('slug', $category['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('elv_kit_categories')->insert($category + ['created_at' => $now, 'updated_at' => $now]);
            $created['categories']++;
        }

        foreach (static::products() as $product) {
            $exists = DB::table('elv_kit_products')->where('slug', $product['slug'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('elv_kit_products')->insert($product + ['created_at' => $now, 'updated_at' => $now]);
            $created['products']++;
        }

        $categoryId = (int) DB::table('elv_kit_categories')->where('slug', 'engineering')->value('id');

        foreach (static::posts() as $post) {
            $exists = DB::table('elv_kit_posts')->where('slug', $post['slug'])->exists();

            if ($exists) {
                continue;
            }

            $post['category_id'] = $post['category_id'] ?: $categoryId;
            DB::table('elv_kit_posts')->insert($post + ['created_at' => $now, 'updated_at' => $now]);
            $created['posts']++;
        }

        return $created;
    }

    /**
     * slots — генерирует сетку для всех услуг демо-бронирования.
     */
    public static function slots(int $days = 21): int
    {
        $created = 0;

        foreach (Slots::services() as $service) {
            $created += Slots::generate((string) $service['code'], $days);
        }

        return $created;
    }

    /**
     * purge — стирает демо-контент, не трогая заказы, лицензии и подписчиков.
     */
    public static function purge(): void
    {
        DB::table('elv_kit_bookings')->delete();
        DB::table('elv_kit_slots')->delete();
        DB::table('elv_kit_messages')->delete();
        DB::table('elv_kit_posts')->delete();
        DB::table('elv_kit_products')->delete();
        DB::table('elv_kit_categories')->delete();
    }

    public static function categories(): array
    {
        return [
            [
                'name' => 'Инженерия',
                'slug' => 'engineering',
                'description' => 'Как устроен Elv Kit и почему он быстрый.',
                'sort_order' => 10,
            ],
            [
                'name' => 'Практика',
                'slug' => 'practice',
                'description' => 'Сценарии, рецепты и разборы внедрения.',
                'sort_order' => 20,
            ],
            [
                'name' => 'SEO и трафик',
                'slug' => 'seo',
                'description' => 'Поиск, разметка и ИИ-выдача Google.',
                'sort_order' => 30,
            ],
        ];
    }

    public static function products(): array
    {
        return [
            [
                'name' => 'Elv Kit',
                'slug' => 'elv-kit',
                'kind' => 'kit',
                'tagline' => 'Тема, модули и демо-данные для October CMS 4.x',
                'description' => 'Модульный конструктор сайтов: ядро (тема + SEO + формы + безопасность) и подключаемые модули — бронирование, заказ товара, виджет обратной связи, блог. Ставится в October CMS, данные остаются на вашем сервере.',
                'price_cents' => 7900,
                'price_multi_cents' => 24900,
                'price_extended_cents' => 49900,
                'badge' => 'Ядро + все модули',
                'icon' => 'kit',
                'demo_url' => '/demo/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Тема на системных шрифтах, CSS инлайн, ноль внешних запросов',
                    'Бронирование с UNIQUE-слотом и lockForUpdate',
                    'Заказ товара и цифровая выдача по токену',
                    'Виджет обратной связи с защитой от спама',
                    'Блог с категориями, тегами, RSS и Article-разметкой',
                    'SEO: sitemap.xml, robots.txt, llms.txt, JSON-LD',
                ]),
                'compatibility' => static::json([
                    'October CMS 4.4+',
                    'PHP 8.3+',
                    'MySQL 8.0+',
                    'Laravel 12',
                    'Bootstrap не требуется',
                ]),
                'changelog' => static::json([
                    ['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Первый публичный релиз: ядро, Booking, Shop, Feedback, Blog, SEO.'],
                ]),
                'meta' => static::json(['css_kb' => 28, 'js_kb' => 8, 'requests' => 0, 'tables' => 10]),
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Elv Booking',
                'slug' => 'booking',
                'kind' => 'module',
                'tagline' => 'Бронирование услуг, помещений и слотов',
                'description' => 'Слоты с уникальным ключом, защита от двойной записи на уровне БД и транзакции. Админка: подтверждение и отмена, освобождение слота.',
                'price_cents' => 4900,
                'badge' => 'Модуль',
                'icon' => 'calendar',
                'demo_url' => '/booking/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'UNIQUE (service_code, date, time)',
                    'lockForUpdate + условный UPDATE taken',
                    'Подтверждение и отмена брони',
                    'Освобождение слота при отмене',
                    'Письма клиенту и администратору',
                ]),
                'compatibility' => static::json(['October CMS 4.x', 'PHP 8.3+', 'MySQL 8+']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз модуля.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 3, 'size_kb' => 64]),
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Elv Shop',
                'slug' => 'shop',
                'kind' => 'module',
                'tagline' => 'Лёгкий заказ товара без Shopaholic',
                'description' => 'Каталог, оформление заказа, оплата через ЮKassa или Stripe, автоматическая выдача файла по токену. Одна таблица заказов вместо десятков.',
                'price_cents' => 3900,
                'badge' => 'Модуль',
                'icon' => 'cart',
                'demo_url' => '/order/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Каталог и карточка товара',
                    'Заказ с серверной валидацией',
                    'ЮKassa и Stripe Checkout',
                    'Подпись вебхука и проверка статуса',
                    'ZIP по download_token после оплаты',
                ]),
                'compatibility' => static::json(['October CMS 4.x', 'PHP 8.3+', 'MySQL 8+']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз модуля.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 4, 'size_kb' => 82]),
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Elv Feedback',
                'slug' => 'feedback',
                'kind' => 'module',
                'tagline' => 'Виджет обратной связи с защитой от спама',
                'description' => 'Форма, honeypot, лимит запросов, запись в БД и письмо администратору. Встраивается в любую страницу October одним partial.',
                'price_cents' => 2900,
                'badge' => 'Модуль',
                'icon' => 'message',
                'demo_url' => '/feedback/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Honeypot без капчи',
                    'Лимит запросов на IP',
                    'Статусы new / in_progress / closed',
                    'Письмо администратору из очереди',
                    'AJAX без внешних библиотек',
                ]),
                'compatibility' => static::json(['October CMS 4.x', 'PHP 8.3+', 'MySQL 8+']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз модуля.']]),
                'meta' => static::json(['tables' => 1, 'queries' => 2, 'size_kb' => 41]),
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'name' => 'Elv Blog',
                'slug' => 'blog',
                'kind' => 'module',
                'tagline' => 'Блог в меню, RSS и Article-разметка',
                'description' => 'Статьи с категориями и тегами, оглавлением и CTA на продукт. Публикация по расписанию, RSS, sitemap и JSON-LD.',
                'price_cents' => 0,
                'badge' => 'Входит в Kit',
                'icon' => 'pencil',
                'demo_url' => '/blog/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Категории и теги',
                    'Черновики и дата публикации',
                    'RSS 2.0 и sitemap',
                    'Article / BlogPosting JSON-LD',
                    'CTA на продукт в статье',
                ]),
                'compatibility' => static::json(['October CMS 4.x', 'PHP 8.3+', 'MySQL 8+']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Блог включён в ядро.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 2, 'size_kb' => 58]),
                'is_active' => true,
                'sort_order' => 40,
            ],
        ];
    }

    public static function posts(): array
    {
        return [
            [
                'title' => 'Почему October CMS, а не конструктор',
                'slug' => 'why-october-cms',
                'excerpt' => 'Конструктор даёт скорость старта, October — владение кодом, транзакциями и ценой владения. Разбираем на цифрах.',
                'body' => '<p>Конструктор выигрывает на старте и проигрывает на дистанции: тема, плагины и данные живут в одном месте, но каждая новая функция — это чужой код.</p><h2>Что важно на дистанции</h2><p>Транзакции, уникальные индексы, очереди и тесты. Бронирование не должно записывать двух клиентов на одно время, заказ не должен терять оплату.</p><p>October CMS — это Laravel: миграции, Eloquent, планировщик, PHPUnit. Elv Kit добавляет готовый контур, а не закрывает его.</p>',
                'tags' => static::json(['October CMS', 'архитектура']),
                'reading_time' => 4,
                'cover' => 'graph',
                'seo_title' => 'Почему October CMS для лендинга с заказами',
                'seo_description' => 'Сравнение конструктора и October CMS: скорость старта против владения кодом, транзакций и SEO.',
                'is_published' => true,
                'published_at' => '2026-11-10 09:00:00',
                'category_id' => null,
                'author' => 'Elv Studio',
            ],
            [
                'title' => 'Защита от овербукинга: UNIQUE против «проверил и записал»',
                'slug' => 'booking-without-race',
                'excerpt' => 'Проверка доступности слота без блокировки — это гонка. Показываем схему с UNIQUE и lockForUpdate, которая держит нагрузку.',
                'body' => '<p>Два клиента одновременно видят свободный слот. Оба проходят проверку, оба пишут — на одно время две записи.</p><h2>Три уровня защиты</h2><ol><li>UNIQUE (service_code, date, time) в БД.</li><li>Транзакция с lockForUpdate.</li><li>Условный UPDATE, который проверяет taken &lt; capacity.</li></ol><p>Только третий пункт реально закрывает гонку: если UPDATE вернул 0 строк, бронь откатывается.</p>',
                'tags' => static::json(['бронирование', 'MySQL']),
                'reading_time' => 5,
                'cover' => 'calendar',
                'seo_title' => 'Защита от двойного бронирования на October CMS',
                'seo_description' => 'Как устроена защита от овербукинга: UNIQUE-индекс, lockForUpdate и условный UPDATE. Пример из Elv Booking.',
                'is_published' => true,
                'published_at' => '2026-11-11 10:30:00',
                'category_id' => null,
                'author' => 'Elv Studio',
            ],
            [
                'title' => 'AI-режим Google: что реально влияет на видимость',
                'slug' => 'seo-for-google-ai',
                'excerpt' => 'Разметка, прямые ответы, llms.txt и скорость. Чек-лист, который можно проверить, а не пересказать.',
                'body' => '<p>ИИ-ответы Google собираются из страниц, которым можно доверять: структура, факты, даты, автор, микроразметка.</p><h2>Чек-лист</h2><ul><li>Один H1 и логичная иерархия H2–H3.</li><li>FAQ с короткими прямыми ответами.</li><li>JSON-LD Organization, Product, FAQPage, BreadcrumbList.</li><li>sitemap.xml, robots.txt, llms.txt.</li><li>Скорость: LCP &lt; 2,5 с, CLS &lt; 0,1.</li></ul><p>llms.txt не заменяет разметку, но даёт модели карту сайта без обхода сотни страниц.</p>',
                'tags' => static::json(['SEO', 'Google', 'AI']),
                'reading_time' => 4,
                'cover' => 'spark',
                'seo_title' => 'SEO для Google, Яндекса и AI-режима',
                'seo_description' => 'Чек-лист видимости в Google AI: микроразметка, llms.txt, FAQ, скорость и структура заголовков.',
                'is_published' => true,
                'published_at' => '2026-11-12 12:00:00',
                'category_id' => null,
                'author' => 'Elv Studio',
            ],
        ];
    }

    protected static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
