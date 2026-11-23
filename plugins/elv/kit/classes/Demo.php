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
     * @param bool $refreshPosts перезаписать тексты демо-статей (нужно миграции обновления)
     * @param bool $refreshCatalog обновить названия и описания категорий и товаров
     * @return array{categories: int, products: int, posts: int}
     */
    public static function seed(bool $refreshPosts = false, bool $refreshCatalog = false): array
    {
        $created = ['categories' => 0, 'products' => 0, 'posts' => 0];
        $now = now();

        foreach (static::categories() as $category) {
            $exists = DB::table('elv_kit_categories')->where('slug', $category['slug'])->exists();

            if ($exists) {
                if ($refreshCatalog) {
                    DB::table('elv_kit_categories')->where('slug', $category['slug'])->update($category + ['updated_at' => $now]);
                }

                continue;
            }

            DB::table('elv_kit_categories')->insert($category + ['created_at' => $now, 'updated_at' => $now]);
            $created['categories']++;
        }

        foreach (static::products() as $product) {
            $exists = DB::table('elv_kit_products')->where('slug', $product['slug'])->exists();

            if ($exists) {
                if ($refreshCatalog) {
                    DB::table('elv_kit_products')->where('slug', $product['slug'])->update($product + ['updated_at' => $now]);
                }

                continue;
            }

            DB::table('elv_kit_products')->insert($product + ['created_at' => $now, 'updated_at' => $now]);
            $created['products']++;
        }

        $categoryId = (int) DB::table('elv_kit_categories')->where('slug', 'engineering')->value('id');
        $categoryIds = DB::table('elv_kit_categories')->pluck('id', 'slug')->all();
        $hasAudience = \Schema::hasColumn('elv_kit_posts', 'audience');

        foreach (static::posts() as $post) {
            $categorySlug = (string) ($post['category'] ?? '');
            unset($post['category']);
            $post['category_id'] = $post['category_id'] ?: (int) ($categoryIds[$categorySlug] ?? $categoryId);
            // Время чтения считается из тела (Post::readingMinutes()), поле — auto.
            $post['reading_time'] = 0;

            if ($hasAudience) {
                $post['audience'] = static::audienceFor((string) $post['slug']);
            }

            $exists = DB::table('elv_kit_posts')->where('slug', $post['slug'])->exists();

            if ($exists) {
                if ($refreshPosts) {
                    DB::table('elv_kit_posts')->where('slug', $post['slug'])->update($post + ['updated_at' => $now]);
                }

                continue;
            }

            DB::table('elv_kit_posts')->insert($post + ['created_at' => $now, 'updated_at' => $now]);
            $created['posts']++;
        }

        return $created;
    }

    /**
     * contacts — заполняет контакты виджета, если владелец их ещё не задал.
     *
     * Значения демонстрационные: в админке (Настройки → Elv Kit → Контакты)
     * их заменяют на реальные, повторный запуск команды ничего не перетирает.
     */
    public static function contacts(): void
    {
        $defaults = [
            'email' => 'sales@example.com',
            'chat_services' => "Хостинг и запуск\nПоддержка сайта\nМодули и плагины October CMS\nСайт с онлайн-записью\nБесплатный аудит\nSEO-продвижение",
            'chat_services_url' => '/services/',
        ];

        foreach ($defaults as $key => $value) {
            if ((string) \Elv\Kit\Models\Settings::get($key) === '') {
                \Elv\Kit\Models\Settings::set($key, $value);
            }
        }
    }

    /**
     * menu — демонстрационный список раздела «Продукты» в шапке.
     * Пустые настройки владельца не перетирает: пункты добавляются в админке.
     */
    public static function menu(): void
    {
        $defaults = [
            'menu_products_label' => 'Продукты',
            'menu_products' => "Elv Kit | /kit/ | Тема и плагин для October CMS\n"
                . "Шаблон | /template/ | Готовый сайт под свой контент\n"
                . "Модули | /modules/ | Запись, магазин, формы, блог",
        ];

        foreach ($defaults as $key => $value) {
            if ((string) \Elv\Kit\Models\Settings::get($key) === '') {
                \Elv\Kit\Models\Settings::set($key, $value);
            }
        }
    }

    /**
     * faq — переносит демонстрационные вопросы из config/*.php в настройки,
     * если владелец их ещё не задал. Консультант и блоки FAQ читают только настройки.
     */
    public static function faq(): void
    {
        $defaults = [
            'faq_items' => Faq::toLines(Faq::configItems('faq')),
            'faq_calculator_items' => Faq::toLines(Faq::configItems('calculator')),
            'faq_services_items' => Faq::toLines(Faq::configItems('services')),
            'faq_seo_items' => Faq::toLines(Faq::configItems('seo')),
            'faq_post_items' => Faq::postToLines(Faq::configPostItems()),
        ];

        foreach ($defaults as $key => $value) {
            if ($value !== '' && (string) \Elv\Kit\Models\Settings::get($key) === '') {
                \Elv\Kit\Models\Settings::set($key, $value);
            }
        }
    }

    /**
     * bookingServices — переносит демо-услуги записи из config/booking.php
     * в настройки, если владелец их ещё не задал.
     */
    public static function bookingServices(): void
    {
        $lines = Slots::servicesToLines(Slots::configServices());

        if ($lines !== '' && (string) \Elv\Kit\Models\Settings::get('booking_services') === '') {
            \Elv\Kit\Models\Settings::set('booking_services', $lines);
        }
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
                'name' => 'Технологии',
                'slug' => 'engineering',
                'description' => 'Как устроен сайт и почему он работает быстро.',
                'sort_order' => 10,
            ],
            [
                'name' => 'Практика',
                'slug' => 'practice',
                'description' => 'Как запустить сайт и вести его каждый день.',
                'sort_order' => 20,
            ],
            [
                'name' => 'SEO и трафик',
                'slug' => 'seo',
                'description' => 'Как сайт находят в Google и Яндексе.',
                'sort_order' => 30,
            ],
            [
                'name' => 'Контент',
                'slug' => 'content',
                'description' => 'Как писать статьи, которые читают и заказывают.',
                'sort_order' => 40,
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
                'tagline' => 'Готовый сайт для бизнеса: запись, заказы и блог',
                'description' => 'Сайт, который принимает клиентов: страницы услуг, онлайн-запись, продажа товаров, обратная связь и блог. Устанавливаем на ваш домен, настраиваем оплату и почту. Всё управляется из одной админки, данные остаются у вас. Интерфейс витрины, админки и писем — на русском (RU), английской версии в поставке нет.',
                'price_cents' => 7900,
                'price_multi_cents' => 24900,
                'price_extended_cents' => 49900,
                'badge' => 'Сайт и все разделы',
                'icon' => 'kit',
                'demo_url' => '/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Готовые страницы: главная, услуги, запись, блог, контакты',
                    'Онлайн-запись без двойных броней',
                    'Заказы и оплата картой, выдача файлов',
                    'Форма обратной связи с защитой от спама',
                    'Блог с категориями, тегами и RSS',
                    'SEO и быстрая загрузка из коробки',
                ]),
                'compatibility' => static::json([
                    'Ваш домен',
                    'Обычный хостинг',
                    'Телефон и компьютер',
                    'Оплата картой',
                    'Почта и Telegram',
                ]),
                'changelog' => static::json([
                    ['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Первый публичный релиз: сайт, онлайн-запись, заказы, обратная связь и блог.'],
                ]),
                'meta' => static::json(['css_kb' => 28, 'js_kb' => 8, 'requests' => 0, 'tables' => 10]),
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Онлайн-запись',
                'slug' => 'booking',
                'kind' => 'module',
                'tagline' => 'Клиенты записываются сами, без звонков и переписки',
                'description' => 'Клиент выбирает услугу, дату и свободное время. Занятое время исчезает из списка, поэтому две записи на одно время не появятся. Вы видите все записи в админке и можете отменить или перенести.',
                'price_cents' => 4900,
                'badge' => 'Раздел',
                'icon' => 'calendar',
                'demo_url' => '/booking/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Услуги, даты и свободные часы',
                    'Защита от двойной записи',
                    'Подтверждение на e-mail',
                    'Отмена освобождает время',
                    'Все записи в одной админке',
                ]),
                'compatibility' => static::json(['Ваш домен', 'Телефон и компьютер', 'Почта']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз раздела.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 3, 'size_kb' => 64]),
                'is_active' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'Заказы и оплата',
                'slug' => 'shop',
                'kind' => 'module',
                'tagline' => 'Продажа товаров и файлов с оплатой картой',
                'description' => 'Покупатель выбирает товар, оформляет заказ и платит на защищённой странице. После оплаты автоматически получает файл или подтверждение. Заказы и статусы видны в админке.',
                'price_cents' => 3900,
                'badge' => 'Раздел',
                'icon' => 'cart',
                'demo_url' => '/order/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Карточки товаров и цены',
                    'Оформление заказа без лишних шагов',
                    'Оплата картой через ЮKassa или Stripe',
                    'Автоматическая выдача файла',
                    'Письмо с подтверждением',
                ]),
                'compatibility' => static::json(['Ваш домен', 'Оплата картой', 'Почта']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз раздела.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 4, 'size_kb' => 82]),
                'is_active' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Обратная связь',
                'slug' => 'feedback',
                'kind' => 'module',
                'tagline' => 'Форма вопросов и заявок с защитой от спама',
                'description' => 'Клиент пишет вопрос прямо на сайте. Сообщение сохраняется в админке и приходит вам на почту. Спам-боты отсекаются без капчи и лишних проверок.',
                'price_cents' => 2900,
                'badge' => 'Раздел',
                'icon' => 'message',
                'demo_url' => '/feedback/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Форма на любой странице',
                    'Защита от спама без капчи',
                    'Уведомление на почту',
                    'Статусы: новое, в работе, закрыто',
                    'Работает без внешних сервисов',
                ]),
                'compatibility' => static::json(['Ваш домен', 'Почта', 'Telegram']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Публичный релиз раздела.']]),
                'meta' => static::json(['tables' => 1, 'queries' => 2, 'size_kb' => 41]),
                'is_active' => true,
                'sort_order' => 30,
            ],
            [
                'name' => 'Блог',
                'slug' => 'blog',
                'kind' => 'module',
                'tagline' => 'Статьи, которые приводят клиентов из поиска',
                'description' => 'Пишите об услугах, товарах и полезных советах. Категории, теги и подписка помогают читателю найти нужное, а поисковые системы видят структуру и даты публикации.',
                'price_cents' => 0,
                'badge' => 'Входит в Elv Kit',
                'icon' => 'pencil',
                'demo_url' => '/blog/',
                'docs_url' => '/docs/',
                'features' => static::json([
                    'Категории и теги',
                    'Публикация по расписанию',
                    'RSS и карта сайта',
                    'Красивые карточки статей',
                    'Кнопка подписки',
                ]),
                'compatibility' => static::json(['Ваш домен', 'Телефон и компьютер', 'Почта']),
                'changelog' => static::json([['version' => '1.0.0', 'date' => '2026-11-14', 'note' => 'Блог включён в состав сайта.']]),
                'meta' => static::json(['tables' => 2, 'queries' => 2, 'size_kb' => 58]),
                'is_active' => true,
                'sort_order' => 40,
            ],
        ];
    }

    public static function posts(): array
    {
        $posts = static::dataset('seed', []);
        $overrides = static::dataset('overrides', []);
        $bySlug = [];

        foreach ($posts as $post) {
            $bySlug[$post['slug']] = $post;
        }

        foreach ($overrides as $post) {
            $bySlug[$post['slug']] = $post;
        }

        foreach (static::dataset('extras', []) as $slug => $extra) {
            if (!isset($bySlug[$slug])) {
                continue;
            }

            $html = $extra['note'] ?? '';
            $links = $extra['links'] ?? [];

            if ($links !== []) {
                $items = [];

                foreach ($links as $link) {
                    $items[] = '<a href="' . $link[0] . '">' . $link[1] . '</a>';
                }

                $html .= '<p><strong>Полезные материалы:</strong> ' . implode(', ', $items) . '.</p>';
            }

            // Один призыв на статью: если в тексте он уже есть (абзац или раздел
            // «Следующий шаг»), второй не дописываем. Фраза «Следующий шаг.» внутри
            // примера — не призыв, её не считаем.
            $hasCta = (bool) preg_match('/Следующий шаг:?<\/strong>|Следующий шаг<\/h2>/u', (string) $bySlug[$slug]['body']);

            if (isset($extra['cta']) && ! $hasCta) {
                $html .= '<p><strong>Следующий шаг:</strong> <a href="' . $extra['cta'][0] . '">' . $extra['cta'][1] . '</a>.</p>';
            }

            $bySlug[$slug]['body'] .= $html;
        }

        return array_values($bySlug);
    }

    /**
     * techPostSlugs — демо-статьи, которые уходят в технический раздел.
     *
     * @return string[]
     */
    public static function techPostSlugs(): array
    {
        return [
            'no-external-requests',
            'css-inline-budget',
            'static-cache-headers',
            'october-migrations-discipline',
            'laravel-queue-without-redis',
            'why-october-cms',
            'payment-webhook-idempotency',
            'priem-oplat-na-sajte',
            'digital-delivery-token',
            'backup-restore-plan',
            'booking-without-race',
            'onlajn-zapis-na-sajte',
            'form-spam-without-captcha',
            'core-web-vitals-lcp',
            'kejs-lcp-12-skriptov',
            'llms-txt',
            'logi-i-monitoring-october-cms',
            'testirovanie-form-i-oplaty',
            'plugin-october-cms',
            'jsonld-ecommerce',
            'sitemap-robots-llms',
            'article-checklist',
            'csrf-xss-sql-bazovaya-zashchita',
            'validaciya-form-server-i-klient',
            'rezervnoe-kopirovanie-sajta',
            'csp-v-iframe-widget',
            'sitemap-i-noindex-chto-kuda',
            'pustye-sostoyaniya-v-cms',
            'kak-uskorit-october-cms',
            'migracii-v-october-cms',
            'og-kartinki-na-php',
        ];
    }

    public static function audienceFor(string $slug): string
    {
        return in_array($slug, static::techPostSlugs(), true) ? 'tech' : 'business';
    }

    public static function hasPostsDataset(): bool
    {
        return is_file(dirname(__DIR__) . '/demo/blog.json');
    }

    /**
     * Пресеты ниш — демонстрационный контент для отдельных демо-стендов.
     *
     * Пресет меняет только настройки (бренд, услуги, FAQ, меню, запись):
     * каталог и статьи остаются данными Elv Kit, а цены продукта живут
     * в `Prices`, чтобы демо-стенд не подменял прайс витрины.
     */
    public const PRESETS = ['booking', 'digital', 'agency', 'expert'];

    /**
     * PRESET_SETTINGS — настройки, которые пресет вправе заменить.
     *
     * Список закрытый: пресет из файла не может переписать валюту, тарифы,
     * платёжные ключи или аналитику. `tools/presets-check.php` сверяет
     * набор ключей в JSON с этим списком.
     */
    public const PRESET_SETTINGS = [
        'brand_name',
        'brand_tagline',
        'brand_description',
        'brand_subtitle',
        'legal_name',
        'email',
        'phone',
        'chat_services',
        'chat_services_url',
        'menu_products_label',
        'menu_products',
        'faq_items',
        'faq_calculator_items',
        'faq_services_items',
        'faq_seo_items',
        'booking_services',
    ];

    public static function availablePresets(): array
    {
        return static::PRESETS;
    }

    public static function presetExists(string $preset): bool
    {
        return in_array($preset, static::PRESETS, true) && is_file(static::presetPath($preset));
    }

    public static function presetPath(string $preset): string
    {
        return dirname(__DIR__) . '/demo/presets/' . $preset . '.json';
    }

    /**
     * presetData — настройки пресета с подставленной валютой.
     *
     * Цены в JSON написаны как «90 {{currency}}»: пресет не знает, в какой
     * валюте продаёт владелец, и подставляет `Money::currency()` при чтении.
     */
    public static function presetData(string $preset): array
    {
        if (!static::presetExists($preset)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents(static::presetPath($preset)), true);

        return is_array($decoded) ? static::withCurrency($decoded, Money::currency()) : [];
    }

    /**
     * presetValues — пары «настройка → значение» для пресета.
     *
     * Отсутствующий ключ берётся из демо-значений по умолчанию, поэтому
     * переключение пресетов не оставляет хвостов от предыдущего.
     */
    public static function presetValues(string $preset): array
    {
        $settings = (array) (static::presetData($preset)['settings'] ?? []);
        $defaults = static::defaultPresetValues();
        $values = [];

        foreach (static::PRESET_SETTINGS as $key) {
            $value = array_key_exists($key, $settings) ? $settings[$key] : ($defaults[$key] ?? '');
            $values[$key] = trim((string) $value);
        }

        return $values;
    }

    /**
     * applyPreset — записывает настройки пресета в базу.
     *
     * @return array<string, string> применённые значения
     */
    public static function applyPreset(string $preset): array
    {
        if (!static::presetExists($preset)) {
            throw new \InvalidArgumentException('Неизвестный пресет «' . $preset . '».');
        }

        $values = static::presetValues($preset);

        \Elv\Kit\Models\Settings::set($values);

        return $values;
    }

    /**
     * resetPresetSettings — возвращает демо-значения бренда и чистит нишевые
     * настройки. Демо-контент (`contacts`, `menu`, `faq`, `bookingServices`)
     * после этого снова заполняет пустые поля значениями Elv Kit.
     */
    public static function resetPresetSettings(): void
    {
        \Elv\Kit\Models\Settings::set(static::defaultPresetValues());
    }

    protected static function defaultPresetValues(): array
    {
        return [
            'brand_name' => 'Elv Kit',
            'brand_tagline' => 'Модульный конструктор сайтов на October CMS',
            'brand_description' => 'Elv Kit — тема, модули и демо-данные для October CMS 4.x.',
            'brand_subtitle' => '',
            'legal_name' => '',
            'email' => '',
            'phone' => '',
            'chat_services' => '',
            'chat_services_url' => '',
            'menu_products_label' => '',
            'menu_products' => '',
            'faq_items' => '',
            'faq_calculator_items' => '',
            'faq_services_items' => '',
            'faq_seo_items' => '',
            'booking_services' => '',
        ];
    }

    protected static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    protected static function dataset(string $key, array $default): array
    {
        static $data;

        if ($data === null) {
            $path = dirname(__DIR__) . '/demo/blog.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $data = is_array($decoded) ? $decoded : [];
            $data = static::withCurrency($data, Money::currency());
        }

        $value = $data[$key] ?? $default;

        return is_array($value) ? $value : $default;
    }

    protected static function withCurrency(array $data, string $currency): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = static::withCurrency($value, $currency);
            } elseif (is_string($value) && str_contains($value, '{{currency}}')) {
                $data[$key] = str_replace('{{currency}}', $currency, $value);
            }
        }

        return $data;
    }
}
