<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;

/**
 * Calculator — единый расчёт стоимости запуска на Elv Kit.
 *
 * Цены тарифов берутся из Classes\Prices (действующая версия прайса, иначе
 * карточка товара), цены модулей — из каталога. Чисел в шаблоне нет.
 * Хостинг и домен студия не продаёт: они оплачиваются провайдеру напрямую.
 */
class Calculator
{
    public static function data(): array
    {
        $plans = [];

        foreach (Catalog::plans() as $plan) {
            $plans[] = [
                'key' => (string) $plan['key'],
                'label' => (string) $plan['label'],
                'price' => (int) $plan['price'],
                'price_label' => Money::format((int) $plan['price']),
                'period' => (string) $plan['period'],
                'sites' => (string) ($plan['sites'] ?? ''),
                'support' => (string) ($plan['support'] ?? ''),
                'updates' => (string) ($plan['updates'] ?? ''),
                'featured' => (bool) ($plan['featured'] ?? false),
                'features' => array_values((array) ($plan['features'] ?? [])),
            ];
        }

        $modules = [];

        foreach (Catalog::modules() as $module) {
            if (!$module instanceof Product) {
                continue;
            }

            $modulePrice = Prices::resolve($module, 'single');

            $modules[] = [
                'slug' => (string) $module->slug,
                'label' => (string) $module->name,
                'tagline' => (string) $module->tagline,
                'price' => $modulePrice,
                'price_label' => $modulePrice > 0
                    ? Money::format($modulePrice)
                    : 'включён в Elv Kit',
                'icon' => (string) ($module->icon ?: 'puzzle'),
                'url' => (string) $module->url,
            ];
        }

        return [
            'enabled' => (bool) Settings::get('calculator_enabled', true),
            'currency' => Money::currency(),
            'symbol' => Money::symbol(),
            'zero' => Money::format(0),
            'prices_updated_at' => Prices::label(),
            'hosting_note' => (string) (Settings::get('calculator_hosting_note') ?: 'Хостинг и домен оплачиваются провайдеру напрямую. Мы не добавляем наценку и не выдаём чужой хостинг за свой.'),
            'support_note' => (string) (Settings::get('calculator_support_note') ?: 'Поддержка уже входит в тариф на указанный срок. Продление после него считается отдельно по объёму работ.'),
            'plans' => $plans,
            'modules' => $modules,
            'recommendations' => static::recommendations(),
            'quote_valid_days' => 14,
        ];
    }

    /**
     * recommendations — рекомендуемый набор для задачи.
     *
     * Это не «лучший тариф», а стартовая точка: один сайт и модули, которые
     * чаще всего нужны для выбранного типа проекта. Цены остаются из каталога.
     *
     * @return array<string, array{plan:string, modules:array<int, string>, reason:string}>
     */
    public static function recommendations(): array
    {
        $keys = Catalog::planKeys();
        $labels = Catalog::planLabels();
        $fallback = (string) ($keys[0] ?? 'single');

        $items = [
            'landing' => [
                'plan' => 'single',
                'modules' => [],
                'reason' => 'Тариф «' . $labels['single'] . '»: блог уже включён. Если сайтов несколько — «' . $labels['multi'] . '».',
            ],
            'services' => [
                'plan' => 'single',
                'modules' => ['booking', 'feedback'],
                'reason' => 'Запись и обратная связь для сайта услуг: «' . $labels['single'] . '». Для 2–5 сайтов — «' . $labels['multi'] . '».',
            ],
            'shop' => [
                'plan' => 'single',
                'modules' => ['shop', 'feedback'],
                'reason' => 'Магазин и обратная связь. Оплата и доставка настраиваются отдельно.',
            ],
            'booking' => [
                'plan' => 'single',
                'modules' => ['booking', 'feedback'],
                'reason' => 'Онлайн-запись и обратная связь: «' . $labels['single'] . '». Для сети сайтов — «' . $labels['multi'] . '».',
            ],
        ];

        foreach ($items as $task => $item) {
            if ($keys && !in_array($item['plan'], $keys, true)) {
                $items[$task]['plan'] = $fallback;
                $items[$task]['reason'] = 'Рекомендуем этот тариф: прежний вариант сейчас не продаётся.';
            }
        }

        return $items;
    }

    /**
     * quote — серверная смета с номером и сроком действия.
     *
     * @param  array<int, string>|string  $moduleSlugs
     * @return array<string, mixed>
     */
    public static function quote(string $planKey, $moduleSlugs = [], string $task = 'landing', string $hosting = 'own'): array
    {
        $estimate = static::estimate($planKey, $moduleSlugs);

        $taskLabels = [
            'landing' => 'Лендинг',
            'services' => 'Сайт услуг',
            'shop' => 'Магазин',
            'booking' => 'Онлайн-запись',
        ];
        $hostingLabels = [
            'own' => 'Свой хостинг',
            'need' => 'Нужен хостинг',
            'help' => 'Нужна помощь с выбором',
        ];
        $issuedAt = date('d.m.Y H:i');
        $validUntil = date('d.m.Y', strtotime('+14 days'));
        $number = 'ELV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $breakdown = array_map(function (array $row): array {
            $row['price_label'] = Money::format((int) $row['price']);

            return $row;
        }, $estimate['breakdown']);

        return [
            'number' => $number,
            'issued_at' => $issuedAt,
            'valid_until' => $validUntil,
            'valid_days' => 14,
            'task' => $task,
            'task_label' => $taskLabels[$task] ?? $task,
            'hosting' => $hosting,
            'hosting_label' => $hostingLabels[$hosting] ?? $hosting,
            'plan' => $estimate['plan'],
            'modules' => $estimate['modules'],
            'breakdown' => $breakdown,
            'launch' => $estimate['launch'],
            'launch_label' => Money::format($estimate['launch']),
            'annual' => $estimate['annual'],
            'annual_label' => Money::format($estimate['annual']),
            'monthly' => $estimate['monthly'],
            'monthly_label' => Money::format($estimate['monthly']),
            'first_year' => $estimate['first_year'],
            'first_year_label' => Money::format($estimate['first_year']),
            'next_year' => $estimate['next_year'],
            'next_year_label' => Money::format($estimate['next_year']),
            'is_offer' => false,
            'email' => (string) Settings::get('email', ''),
            'telegram' => (string) Settings::get('telegram', ''),
        ];
    }

    /**
     * estimate — формула для тестов и будущих серверных смет.
     *
     * @param  array<int, string>|string  $moduleSlugs
     * @return array{launch:int, annual:int, monthly:int, first_year:int, next_year:int, plan:array<string, mixed>, modules:array<int, array<string, mixed>>, breakdown:array<int, array<string, mixed>>}
     */
    public static function estimate(string $planKey, $moduleSlugs = []): array
    {
        $data = static::data();
        $plan = null;

        foreach ($data['plans'] as $candidate) {
            if ($candidate['key'] === $planKey) {
                $plan = $candidate;
                break;
            }
        }

        if ($plan === null && $data['plans'] !== []) {
            $plan = $data['plans'][0];
        }

        if ($plan === null) {
            return [
                'launch' => 0,
                'annual' => 0,
                'monthly' => 0,
                'first_year' => 0,
                'next_year' => 0,
                'plan' => [],
                'modules' => [],
                'breakdown' => [],
            ];
        }

        if (!is_array($moduleSlugs)) {
            $moduleSlugs = array_filter(array_map('trim', explode(',', (string) $moduleSlugs)));
        }

        $wanted = array_fill_keys(array_map('strval', $moduleSlugs), true);
        $selected = [];
        $breakdown = [
            [
                'type' => 'plan',
                'label' => $plan['label'],
                'price' => (int) $plan['price'],
            ],
        ];

        foreach ($data['modules'] as $module) {
            if (!isset($wanted[$module['slug']])) {
                continue;
            }

            $selected[] = $module;
            $breakdown[] = [
                'type' => 'module',
                'label' => $module['label'],
                'price' => (int) $module['price'],
            ];
        }

        $launch = (int) $plan['price'];

        foreach ($selected as $module) {
            $launch += (int) $module['price'];
        }

        $annual = 0;
        $monthly = 0;

        return [
            'launch' => $launch,
            'annual' => $annual,
            'monthly' => $monthly,
            'first_year' => $launch + $annual + ($monthly * 12),
            'next_year' => $annual + ($monthly * 12),
            'plan' => $plan,
            'modules' => $selected,
            'breakdown' => $breakdown,
        ];
    }
}
