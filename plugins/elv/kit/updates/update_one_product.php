<?php

use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use October\Rain\Database\Updates\Migration;

/**
 * Один продукт: Elv Kit.
 *
 * До 1.5.0 витрина продавала шаблон, плагины и разделы по отдельности.
 * Миграция приводит данные существующей установки к одной модели:
 * разделы входят в Elv Kit, их цена — ноль, старые товары-«продукты»
 * выключаются, меню «Продукт» и FAQ больше не обещают отдельную продажу.
 *
 * Демо-контент не создаётся: на товарной установке его быть не должно.
 */
return new class extends Migration
{
    /**
     * Старые демо-товары витрины, которые больше не продаются.
     */
    protected array $legacySlugs = ['booking-script', 'cms-template', 'desktop-tool'];

    public function up()
    {
        $now = date('Y-m-d H:i:s');

        if (Schema::hasTable('elv_kit_products')) {
            DB::table('elv_kit_products')
                ->where('kind', 'module')
                ->update([
                    'price_cents' => 0,
                    'price_multi_cents' => 0,
                    'price_extended_cents' => 0,
                    'badge' => 'Входит в Elv Kit',
                    'updated_at' => $now,
                ]);

            if (Schema::hasTable('elv_kit_price_versions')) {
                DB::table('elv_kit_price_versions')
                    ->whereIn('product_slug', function ($query) {
                        $query->select('slug')->from('elv_kit_products')->where('kind', 'module');
                    })
                    ->update(['is_active' => false, 'updated_at' => $now]);
            }

            DB::table('elv_kit_products')
                ->whereIn('slug', $this->legacySlugs)
                ->update(['is_active' => false, 'updated_at' => $now]);

            DB::table('elv_kit_products')
                ->whereIn('kind', ['software', 'template'])
                ->update(['is_active' => false, 'updated_at' => $now]);
        }

        $this->rewriteMenu();
        $this->rewriteServicesFaq();
        $this->rewriteBrand();
    }

    public function down()
    {
        // Данные не восстанавливаются: вернуть разделы в отдельную продажу
        // одной миграцией нельзя, старые цены и товары уже переписаны.
    }

    /**
     * Меню «Продукт»: один пункт Elv Kit вместо списка разработок.
     */
    protected function rewriteMenu(): void
    {
        $label = trim((string) Settings::get('menu_products_label'));

        if ($label === '' || in_array($label, ['Продукты', 'Разработки'], true)) {
            Settings::set('menu_products_label', 'Продукт');
        }

        $raw = trim((string) Settings::get('menu_products'));

        if ($raw === '') {
            return;
        }

        $kept = [];
        $kit = null;

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('|', $line));
            $url = (string) ($parts[1] ?? '');

            if ($url === '' || str_starts_with($url, '/template')) {
                continue;
            }

            if (str_starts_with($url, '/kit')) {
                $kit = $line;
                break;
            }

            $kept[] = $line;
        }

        if ($kit !== null) {
            $kept = [$kit];
        } elseif ($kept === []) {
            $kept = ['Elv Kit | /kit/ | Тема и плагин для October CMS'];
        } else {
            $kept = [$kept[0]];
        }

        Settings::set('menu_products', implode("\n", $kept));
    }

    /**
     * FAQ страницы услуг: убирает ответы про отдельную продажу разделов.
     */
    protected function rewriteServicesFaq(): void
    {
        $raw = trim((string) Settings::get('faq_services_items'));

        if ($raw === '') {
            return;
        }

        $replacement = 'Разделы продаются отдельно? | Нет. Онлайн-запись, заказы и оплата, обратная связь и блог уже входят в Elv Kit на любом тарифе. Отдельно докупать разделы не нужно.';
        $lines = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $lower = mb_strtolower($line);
            $sellsModules = mb_strpos($lower, 'модул') !== false
                && (mb_strpos($lower, 'отдельн') !== false
                    || mb_strpos($lower, 'докупить') !== false
                    || mb_strpos($lower, 'подключить только') !== false
                    || mb_strpos($lower, 'по отдельности') !== false);

            $lines[] = $sellsModules ? $replacement : $line;
        }

        if ($lines !== []) {
            Settings::set('faq_services_items', implode("\n", $lines));
        }
    }

    /**
     * Подпись и описание бренда: старые демо-значения говорили о «модулях».
     * Пользовательские значения не трогаем — меняем только точные совпадения.
     */
    protected function rewriteBrand(): void
    {
        $map = [
            'brand_tagline' => [
                'Модульный конструктор сайтов на October CMS',
                'Готовый сайт для бизнеса на October CMS',
            ],
            'brand_description' => [
                'Elv Kit — тема, модули и демо-данные для October CMS 4.x.',
                'Elv Kit — тема и плагин с готовыми разделами и демо-данными для October CMS 4.x.',
            ],
        ];

        foreach ($map as $key => [$old, $new]) {
            if (trim((string) Settings::get($key)) === $old) {
                Settings::set($key, $new);
            }
        }
    }
};
