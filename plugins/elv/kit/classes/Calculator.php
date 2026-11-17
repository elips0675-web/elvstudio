<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;

/**
 * Calculator — единый расчёт стоимости запуска на Elv Kit.
 *
 * Цены берутся только из каталога (Product/Settings), без чисел в шаблоне.
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

            $modules[] = [
                'slug' => (string) $module->slug,
                'label' => (string) $module->name,
                'tagline' => (string) $module->tagline,
                'price' => (int) $module->price_cents,
                'price_label' => (int) $module->price_cents > 0
                    ? Money::format((int) $module->price_cents)
                    : 'включён в Elv Kit',
                'icon' => (string) ($module->icon ?: 'puzzle'),
                'url' => (string) $module->url,
            ];
        }

        return [
            'enabled' => (bool) Settings::get('calculator_enabled', true),
            'currency' => Money::currency(),
            'symbol' => Money::symbol(),
            'prices_updated_at' => (string) (Settings::get('prices_updated_at') ?: '17.11.2026'),
            'hosting_note' => (string) (Settings::get('calculator_hosting_note') ?: 'Хостинг и домен оплачиваются провайдеру напрямую. Мы не добавляем наценку и не выдаём чужой хостинг за свой.'),
            'support_note' => (string) (Settings::get('calculator_support_note') ?: 'Поддержка уже входит в тариф на указанный срок. Продление после него считается отдельно по объёму работ.'),
            'plans' => $plans,
            'modules' => $modules,
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
