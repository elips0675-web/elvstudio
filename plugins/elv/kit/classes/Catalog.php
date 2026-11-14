<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Product;

/**
 * Catalog — данные витрины: тарифы, сравнение, «что входит», анатомия.
 */
class Catalog
{
    public static function kit(): ?Product
    {
        return Product::kit();
    }

    public static function modules()
    {
        return Product::modules();
    }

    public static function find(string $slug): ?Product
    {
        return Product::query()->where('slug', $slug)->where('is_active', true)->first();
    }

    /**
     * plans — три лицензии одного продукта. Цена в центах, подпись — в Money.
     */
    public static function plans(?Product $product = null): array
    {
        $product = $product ?: static::kit();

        if (!$product) {
            return [];
        }

        return [
            [
                'key' => 'single',
                'label' => 'Single Site',
                'price' => (int) $product->price_cents,
                'period' => 'разово · 1 сайт',
                'featured' => false,
                'features' => [
                    'Все функции ядра',
                    '1 production-сайт',
                    'Поддержка 30 дней',
                    'Обновления 12 месяцев',
                ],
            ],
            [
                'key' => 'multi',
                'label' => 'Multi-Site',
                'price' => (int) ($product->price_multi_cents ?: $product->price_cents),
                'period' => 'разово · до 5 сайтов',
                'featured' => true,
                'features' => [
                    'Все функции ядра',
                    '5 проектов',
                    'Приоритетная поддержка 90 дней',
                    'Обновления 12 месяцев',
                    'Установка на 1 сайт',
                ],
            ],
            [
                'key' => 'extended',
                'label' => 'Extended',
                'price' => (int) ($product->price_extended_cents ?: $product->price_cents),
                'period' => 'разово · без ограничений',
                'featured' => false,
                'features' => [
                    'Все функции ядра',
                    'Неограниченно сайтов',
                    'Поддержка 1 год',
                    'Пожизненные обновления',
                    'Исходный код без обфускации',
                ],
            ],
        ];
    }

    /**
     * comparison — построчное сравнение тарифов.
     */
    public static function comparison(): array
    {
        return [
            ['name' => 'Тема лендинга и шаблоны', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Модуль Booking (lockForUpdate + UNIQUE)', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Модуль Shop и выдача по токену', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Виджет обратной связи', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Блог и SEO-контур', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Обновления', 'values' => ['12 мес.', '12 мес.', 'пожизненно']],
            ['name' => 'Поддержка', 'values' => ['30 дней', '90 дней', '1 год']],
            ['name' => 'Установка на хостинг', 'values' => ['no', '1 сайт', 'да']],
            ['name' => 'Настройка SMTP и платежей', 'values' => ['no', 'no', 'да']],
            ['name' => 'Адаптация под бренд', 'values' => ['no', 'no', 'базовая']],
            ['name' => 'Исходный код без обфускации', 'values' => ['no', 'no', 'yes']],
        ];
    }

    public static function includes(): array
    {
        return [
            'Тема лендинга (адаптив, системные шрифты)',
            'Бронирование с защитой от гонок',
            'Заказы, оплата и цифровая выдача',
            'Виджет обратной связи',
            'Блог, RSS и sitemap',
            'SEO: JSON-LD, llms.txt, Clean-param',
            'Админка для заказов, броней и статей',
            'Документация и демо-данные',
        ];
    }

    public static function excludes(): array
    {
        return [
            'Хостинг и домен',
            'Наполнение контентом',
            'Настройка SMTP и платёжных ключей',
            'Интеграции с CRM и 1С',
            'Кастомные доработки сверх ТЗ',
            'Логотип и фирменный стиль',
            'Копирайтинг и продвижение',
            'Обучение October CMS',
        ];
    }

    /**
     * anatomy — карта интеграции: что именно добавляет плагин.
     */
    public static function anatomy(): array
    {
        return [
            [
                'title' => 'Таблицы',
                'items' => ['elv_kit_products', 'elv_kit_orders', 'elv_kit_slots', 'elv_kit_bookings', 'elv_kit_posts', 'elv_kit_licenses'],
            ],
            [
                'title' => 'Маршруты',
                'items' => ['POST /order', 'POST /booking', 'POST /feedback', 'POST /payment/webhook', 'GET /download/{token}', 'GET /sitemap.xml'],
            ],
            [
                'title' => 'Админка',
                'items' => ['Заказы и статусы', 'Брони и слоты', 'Сообщения', 'Товары', 'Статьи блога', 'Лицензии'],
            ],
            [
                'title' => 'Права',
                'items' => ['elv.kit.orders', 'elv.kit.bookings', 'elv.kit.messages', 'elv.kit.products', 'elv.kit.blog', 'elv.kit.licenses'],
            ],
        ];
    }

    /**
     * scenarios — кому подходит, без выдуманных отзывов и логотипов.
     */
    public static function scenarios(): array
    {
        return [
            [
                'role' => 'Веб-студия',
                'text' => 'Нужен проверенный каркас, чтобы не писать бронирование и оплату в каждом проекте. Multi-Site закрывает пять клиентов.',
                'icon' => 'team',
            ],
            [
                'role' => 'Фрилансер',
                'text' => 'Один сайт, один бюджет. Single Site даёт тему, блог и модули без ежемесячной подписки конструктору.',
                'icon' => 'user',
            ],
            [
                'role' => 'Владелец бизнеса',
                'text' => 'Заявки, брони и оплата остаются в своей БД. Данные не уезжают в чужой сервис, заказы видны в админке.',
                'icon' => 'store',
            ],
        ];
    }

    /**
     * changelog — записи из карточки Kit, по убыванию даты.
     */
    public static function changelog(): array
    {
        $kit = static::kit();
        $entries = $kit && is_array($kit->changelog) ? $kit->changelog : [];

        usort($entries, function ($a, $b) {
            return strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        });

        return $entries;
    }
}
