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
                'label' => 'Один сайт',
                'price' => (int) $product->price_cents,
                'period' => 'разово · 1 сайт',
                'featured' => false,
                'features' => [
                    'Все разделы и функции',
                    'Один сайт',
                    'Поддержка 30 дней',
                    'Обновления 12 месяцев',
                ],
            ],
            [
                'key' => 'multi',
                'label' => 'До 5 сайтов',
                'price' => (int) ($product->price_multi_cents ?: $product->price_cents),
                'period' => 'разово · до 5 сайтов',
                'featured' => true,
                'features' => [
                    'Все разделы и функции',
                    'До 5 сайтов',
                    'Приоритетная поддержка 90 дней',
                    'Обновления 12 месяцев',
                    'Установка на один сайт',
                ],
            ],
            [
                'key' => 'extended',
                'label' => 'Без ограничений',
                'price' => (int) ($product->price_extended_cents ?: $product->price_cents),
                'period' => 'разово · без ограничений',
                'featured' => false,
                'features' => [
                    'Все разделы и функции',
                    'Неограниченное число сайтов',
                    'Поддержка 1 год',
                    'Обновления навсегда',
                    'Файлы сайта без шифрования',
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
            ['name' => 'Готовые страницы и дизайн', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Онлайн-запись', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Заказы и оплата', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Форма обратной связи', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Блог и SEO', 'values' => ['yes', 'yes', 'yes']],
            ['name' => 'Обновления', 'values' => ['12 мес.', '12 мес.', 'навсегда']],
            ['name' => 'Поддержка', 'values' => ['30 дней', '90 дней', '1 год']],
            ['name' => 'Установка на хостинг', 'values' => ['no', '1 сайт', 'да']],
            ['name' => 'Настройка почты и оплаты', 'values' => ['no', 'no', 'да']],
            ['name' => 'Оформление под ваш бренд', 'values' => ['no', 'no', 'базовое']],
            ['name' => 'Файлы сайта без шифрования', 'values' => ['no', 'no', 'yes']],
        ];
    }

    public static function includes(): array
    {
        return [
            'Готовый сайт с адаптивным дизайном',
            'Онлайн-запись без двойных броней',
            'Заказы, оплата и выдача файлов',
            'Форма обратной связи',
            'Блог, RSS и карта сайта',
            'SEO: заголовки, описания и разметка',
            'Админка для заказов, записей и статей',
            'Инструкция и демо-данные',
        ];
    }

    public static function excludes(): array
    {
        return [
            'Хостинг и домен',
            'Тексты и фотографии для сайта',
            'Настройка почты и платёжных ключей',
            'Интеграции с CRM и 1С',
            'Доработки сверх выбранного тарифа',
            'Логотип и фирменный стиль',
            'Продвижение и реклама',
            'Обучение сверх инструкции',
        ];
    }

    /**
     * anatomy — что получает владелец сайта, без внутренних таблиц и маршрутов.
     */
    public static function anatomy(): array
    {
        return [
            [
                'title' => 'Страницы',
                'items' => ['Главная', 'Услуги', 'Онлайн-запись', 'Заказы', 'Блог', 'Контакты'],
            ],
            [
                'title' => 'Сценарии',
                'items' => ['Запись на услугу', 'Покупка и оплата', 'Вопрос с сайта', 'Подписка на статьи'],
            ],
            [
                'title' => 'Админка',
                'items' => ['Заказы', 'Записи', 'Сообщения', 'Товары', 'Статьи', 'Настройки'],
            ],
            [
                'title' => 'Сопровождение',
                'items' => ['Инструкция', 'Демо-данные', 'Обновления', 'Поддержка'],
            ],
        ];
    }

    /**
     * scenarios — кому подходит готовый сайт.
     */
    public static function scenarios(): array
    {
        return [
            [
                'role' => 'Мастер или специалист',
                'text' => 'Салоны, мастера и консультанты: клиент выбирает услугу и время сам, без звонков и переписки.',
                'icon' => 'user',
            ],
            [
                'role' => 'Небольшой магазин',
                'text' => 'Товары, заказы и оплата без сложной системы: покупатель платит картой, вы видите заказ в админке.',
                'icon' => 'store',
            ],
            [
                'role' => 'Эксперт или автор',
                'text' => 'Блог приводит читателей из поиска, а форма обратной связи собирает вопросы и заявки.',
                'icon' => 'pencil',
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
