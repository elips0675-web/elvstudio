<?php

/**
 * price-check — версии прайса: выбор действующей цены, дата, интеграция.
 *
 * Проверка идёт в транзакции с откатом: реальные версии цен не меняются.
 * Запуск: php tools/price-check.php
 */

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Calculator;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\Money;
use Elv\Kit\Classes\Prices;
use Elv\Kit\Models\Price;
use Elv\Kit\Models\Product;

function priceFail(string $message): void
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function insertVersion(array $row): int
{
    $now = date('Y-m-d H:i:s');

    return (int) Db::table('elv_kit_price_versions')->insertGetId(array_merge([
        'product_id' => null,
        'product_slug' => 'elv-kit',
        'plan' => 'single',
        'price_cents' => 0,
        'currency' => 'USD',
        'valid_from' => date('Y-m-d'),
        'valid_to' => null,
        'is_active' => true,
        'note' => 'price-check',
        'created_by' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ], $row));
}

function dropVersion(array $ids): void
{
    Db::table('elv_kit_price_versions')->whereIn('id', $ids)->delete();
    Prices::flush();
}

$kit = Product::kit();

if (!$kit) {
    priceFail('в каталоге нет активной карточки Elv Kit');
}

$plans = Catalog::plans();

if ($plans === []) {
    priceFail('каталог не отдаёт тарифы');
}

$catalogPrices = [];

foreach ($plans as $plan) {
    $catalogPrices[$plan['key']] = $kit->planPrice($plan['key']);
}

if (Prices::rows() === []) {
    priceFail('прайс пуст: миграция create_price_versions не применена');
}

foreach ($catalogPrices as $planKey => $catalogPrice) {
    $resolved = Prices::current((string) $kit->slug, $planKey);

    if ($resolved !== $catalogPrice) {
        priceFail('стартовая версия ' . $planKey . ' = ' . var_export($resolved, true) . ', в каталоге ' . $catalogPrice);
    }
}

$label = Prices::label();

if (!preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $label)) {
    priceFail('дата цен не в формате d.m.Y: ' . $label);
}

if (Prices::updatedAt() !== $label) {
    priceFail('label расходится с последней версией прайса');
}

$base = (int) $catalogPrices['single'];
$slug = (string) $kit->slug;
$created = [];
$today = date('Y-m-d');

Db::beginTransaction();

try {
    $created[] = insertVersion(['price_cents' => $base + 1000, 'valid_from' => date('Y-m-d', strtotime('+1 day'))]);

    if (Prices::current($slug, 'single') !== $base) {
        priceFail('будущая версия уже действует');
    }

    dropVersion($created);
    $created = [];

    $created[] = insertVersion([
        'price_cents' => $base + 2000,
        'valid_from' => date('Y-m-d', strtotime('-1 day')),
        'valid_to' => date('Y-m-d', strtotime('-1 day')),
    ]);

    if (Prices::current($slug, 'single') !== $base) {
        priceFail('истёкшая версия продолжает действовать');
    }

    dropVersion($created);
    $created = [];

    $created[] = insertVersion([
        'price_cents' => $base + 3000,
        'valid_from' => date('Y-m-d', strtotime('-1 day')),
        'is_active' => false,
    ]);

    if (Prices::current($slug, 'single') !== $base) {
        priceFail('выключенная версия участвует в выборе');
    }

    dropVersion($created);
    $created = [];

    $created[] = insertVersion([
        'price_cents' => $base + 4000,
        'valid_from' => date('Y-m-d', strtotime('-1 day')),
        'valid_to' => $today,
    ]);

    if (Prices::current($slug, 'single') !== $base + 4000) {
        priceFail('версия, действующая по сегодняшний день включительно, не выбрана');
    }

    dropVersion($created);
    $created = [];

    $created[] = insertVersion([
        'product_slug' => 'other-product',
        'price_cents' => $base + 5000,
        'valid_from' => date('Y-m-d', strtotime('-1 day')),
    ]);

    if (Prices::current($slug, 'single') !== $base) {
        priceFail('цена другого товара попала в тариф Elv Kit');
    }

    dropVersion($created);
    $created = [];

    $freshPrice = $base + 700;
    $freshFrom = date('Y-m-d', strtotime('-1 day'));
    $created[] = insertVersion(['price_cents' => $freshPrice, 'valid_from' => $freshFrom]);

    if (Prices::current($slug, 'single') !== $freshPrice) {
        priceFail('действующая версия не перекрывает каталог');
    }

    $plansNow = [];

    foreach (Catalog::plans() as $plan) {
        $plansNow[$plan['key']] = (int) $plan['price'];
    }

    if (($plansNow['single'] ?? 0) !== $freshPrice) {
        priceFail('/pricing/ и Консультант не видят действующую версию');
    }

    if (($plansNow['multi'] ?? 0) !== $catalogPrices['multi']) {
        priceFail('версия одного тарифа изменила соседний');
    }

    $calculator = Calculator::data();

    if (($calculator['plans'][0]['price'] ?? null) !== $freshPrice) {
        priceFail('калькулятор не видит действующую версию');
    }

    if ($calculator['prices_updated_at'] !== date('d.m.Y', strtotime($freshFrom))) {
        priceFail('дата цен не следует за действующей версией');
    }

    $estimate = Calculator::estimate('single', []);

    if ($estimate['launch'] !== $freshPrice) {
        priceFail('смета считает по старой цене');
    }

    $quote = Calculator::quote('single', [], 'landing', 'own');

    if ($quote['launch'] !== $freshPrice || $quote['first_year'] !== $freshPrice) {
        priceFail('смета-PDF считает по старой цене');
    }

    $answer = \Elv\Kit\Classes\Chat::answer('сколько стоит Elv Kit');

    if (($answer['rejected'] ?? false) !== false || mb_strpos((string) ($answer['answer'] ?? ''), Money::format($freshPrice)) === false) {
        priceFail('Консультант отвечает старой ценой');
    }

    dropVersion($created);
    $created = [];
} finally {
    dropVersion($created);
    Db::rollBack();
    Prices::flush();
}

$invalid = new Price([
    'product_slug' => $slug,
    'plan' => 'yearly',
    'price_cents' => 100,
    'valid_from' => $today,
]);

try {
    $invalid->validate();

    priceFail('модель приняла тариф вне списка');
} catch (Exception $e) {
}

$backwards = new Price([
    'product_slug' => $slug,
    'plan' => 'single',
    'price_cents' => 100,
    'valid_from' => $today,
    'valid_to' => date('Y-m-d', strtotime('-2 days')),
]);

try {
    $backwards->validate();

    priceFail('модель приняла дату окончания раньше начала');
} catch (Exception $e) {
}

if (!class_exists(\Elv\Kit\Controllers\Prices::class)) {
    priceFail('нет контроллера админки elv/kit/prices');
}

$fresh = new Price([
    'plan' => 'single',
    'price_cents' => 7900,
    'valid_from' => $today,
]);

try {
    $fresh->validate();
} catch (Exception $e) {
    priceFail('новая версия не проходит валидацию без product_slug: ' . $e->getMessage());
}

if ((string) $fresh->product_slug !== $slug) {
    priceFail('product_slug не подставляется из каталога до валидации');
}

if ((string) $fresh->currency === '') {
    priceFail('валюта версии не заполняется');
}

$columnsFile = base_path('plugins/elv/kit/models/price/columns.yaml');
$columns = \Symfony\Component\Yaml\Yaml::parseFile($columnsFile)['columns'] ?? [];

if ($columns === []) {
    priceFail('columns.yaml прайса пуст');
}

foreach ($columns as $name => $config) {
    if (!empty($config['select']) && !Schema::hasColumn('elv_kit_price_versions', (string) $config['select'])) {
        priceFail('колонка ' . $name . ' выбирает несуществующее поле ' . $config['select']);
    }

    if (!empty($config['sortable']) && !empty($config['valueFrom'])) {
        priceFail('колонка ' . $name . ' сортируется по аксессору: Unknown column');
    }

    if (!empty($config['searchable']) && !Schema::hasColumn('elv_kit_price_versions', (string) $name)) {
        priceFail('колонка ' . $name . ' ищет по аксессору: Unknown column');
    }

    if (!empty($config['valueFrom'])) {
        $accessor = 'get' . \Illuminate\Support\Str::studly((string) $config['valueFrom']) . 'Attribute';

        if (!method_exists($fresh, $accessor) && !Schema::hasColumn('elv_kit_price_versions', (string) $config['valueFrom'])) {
            priceFail('колонка ' . $name . ' ссылается на несуществующий аксессор ' . $config['valueFrom']);
        }
    }
}

$listConfig = \Symfony\Component\Yaml\Yaml::parseFile(base_path('plugins/elv/kit/controllers/prices/config_list.yaml'));
$sortColumn = (string) ($listConfig['defaultSort']['column'] ?? '');

if ($sortColumn !== '' && !Schema::hasColumn('elv_kit_price_versions', $sortColumn)) {
    priceFail('список прайса сортируется по несуществующему полю ' . $sortColumn);
}

$fieldsConfig = \Symfony\Component\Yaml\Yaml::parseFile(base_path('plugins/elv/kit/models/price/fields.yaml'));

foreach (array_keys((array) ($fieldsConfig['fields'] ?? [])) as $field) {
    $accessor = 'get' . \Illuminate\Support\Str::studly((string) $field) . 'Attribute';

    if (!Schema::hasColumn('elv_kit_price_versions', (string) $field) && !method_exists($fresh, $accessor)) {
        priceFail('поле формы ' . $field . ' не существует в модели');
    }
}

if (Db::table('elv_kit_price_versions')->where('note', 'price-check')->count() !== 0) {
    priceFail('проверка оставила мусор в прайсе');
}

echo 'OK: versions=' . count(Prices::rows())
    . ' plans=' . count($plans)
    . ' base=' . $base
    . ' fresh=' . ($base + 700)
    . ' label=' . $label
    . "\n";
