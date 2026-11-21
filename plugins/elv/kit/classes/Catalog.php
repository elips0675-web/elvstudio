<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;

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
     * planLabels — подписи тарифов из Settings.
     *
     * Ключи неизменны: на них ссылаются Order, License и Prices. Подпись —
     * витрина покупателя, поэтому её задаёт владелец сайта, а не код.
     *
     * @return array<string, string>
     */
    public static function planLabels(): array
    {
        return [
            'single' => (string) (Settings::get('plan_single_label') ?: 'Один сайт'),
            'multi' => (string) (Settings::get('plan_multi_label') ?: 'До 5 сайтов'),
            'extended' => (string) (Settings::get('plan_extended_label') ?: 'Без ограничений'),
        ];
    }

    public static function planLabel(string $key): string
    {
        $labels = static::planLabels();

        return $labels[$key] ?? $key;
    }

    /**
     * planEnabled — тариф продаётся сейчас. Не задано в настройках — включён:
     * новая установка обязана показывать все три, как раньше.
     */
    public static function planEnabled(string $key): bool
    {
        $flag = Settings::get('plan_' . $key . '_on');

        return $flag === null ? true : (bool) $flag;
    }

    /**
     * planKeys — ключи тарифов, которые показываются и принимаются в заказе.
     *
     * @return array<int, string>
     */
    public static function planKeys(): array
    {
        $keys = [];

        foreach (array_keys(static::planLabels()) as $key) {
            if (static::planEnabled($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * planDomainLimit — сколько доменов даёт лицензия тарифа.
     */
    public static function planDomainLimit(string $key): int
    {
        return match ($key) {
            'extended' => 1000,
            'multi' => 5,
            default => 1,
        };
    }

    /**
     * planDefinitions — что входит в тариф, кроме подписи из Settings.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function planDefinitions(): array
    {
        return [
            'single' => [
                'period' => 'разово · 1 сайт',
                'sites' => '1 сайт',
                'support' => '30 дней',
                'updates' => '12 месяцев',
                'featured' => false,
                'features' => [
                    'Все разделы и функции',
                    'Один сайт',
                    'Поддержка 30 дней',
                    'Обновления 12 месяцев',
                ],
            ],
            'multi' => [
                'period' => 'разово · до 5 сайтов',
                'sites' => 'до 5 сайтов',
                'support' => '90 дней',
                'updates' => '12 месяцев',
                'featured' => true,
                'features' => [
                    'Все разделы и функции',
                    'До 5 сайтов',
                    'Приоритетная поддержка 90 дней',
                    'Обновления 12 месяцев',
                    'Установка на один сайт',
                ],
            ],
            'extended' => [
                'period' => 'разово · без ограничений',
                'sites' => 'без ограничений',
                'support' => '1 год',
                'updates' => 'навсегда',
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
     * plans — лицензии одного продукта. Цена в центах, подпись — в Money.
     */
    public static function plans(?Product $product = null): array
    {
        $product = $product ?: static::kit();

        if (!$product) {
            return [];
        }

        $labels = static::planLabels();
        $definitions = static::planDefinitions();
        $plans = [];

        foreach (static::planKeys() as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }

            $plans[] = array_merge([
                'key' => $key,
                'label' => $labels[$key],
                'price' => Prices::resolve($product, $key),
            ], $definitions[$key]);
        }

        return $plans;
    }

    /**
     * comparison — построчное сравнение тарифов. Значения привязаны к ключу
     * тарифа, поэтому выключение тарифа не сдвигает колонки.
     */
    public static function comparison(): array
    {
        return [
            ['name' => 'Онлайн-запись', 'values' => ['single' => 'yes', 'multi' => 'yes', 'extended' => 'yes']],
            ['name' => 'Готовые страницы и дизайн', 'values' => ['single' => 'yes', 'multi' => 'yes', 'extended' => 'yes']],
            ['name' => 'Заказы и оплата', 'values' => ['single' => 'yes', 'multi' => 'yes', 'extended' => 'yes']],
            ['name' => 'Форма обратной связи', 'values' => ['single' => 'yes', 'multi' => 'yes', 'extended' => 'yes']],
            ['name' => 'Блог и SEO', 'values' => ['single' => 'yes', 'multi' => 'yes', 'extended' => 'yes']],
            ['name' => 'Обновления', 'values' => ['single' => '12 мес.', 'multi' => '12 мес.', 'extended' => 'навсегда']],
            ['name' => 'Поддержка', 'values' => ['single' => '30 дней', 'multi' => '90 дней', 'extended' => '1 год']],
            ['name' => 'Установка на хостинг', 'values' => ['single' => 'no', 'multi' => '1 сайт', 'extended' => 'да']],
            ['name' => 'Настройка почты и оплаты', 'values' => ['single' => 'no', 'multi' => 'no', 'extended' => 'да']],
            ['name' => 'Оформление под ваш бренд', 'values' => ['single' => 'no', 'multi' => 'no', 'extended' => 'базовое']],
            ['name' => 'Файлы сайта без шифрования', 'values' => ['single' => 'no', 'multi' => 'no', 'extended' => 'yes']],
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
            'Настройка почты и платёжных ключей сверх тарифа',
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
