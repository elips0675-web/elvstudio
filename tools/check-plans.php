<?php

/**
 * check-plans — тарифы приходят из Settings, а не из кода.
 *
 * Вызывается из `tools/check-plans.mjs`, отдельно запускать не нужно.
 * Настройки меняются только в памяти процесса: сохранения нет, рабочая
 * база остаётся как была.
 */

require __DIR__ . '/../bootstrap/autoload.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    fwrite(STDERR, 'PHP: ' . $message . ' (' . $file . ':' . $line . ')' . PHP_EOL);

    return true;
});

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Calculator;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\Http\OrderController;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Price;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;

$problems = [];
$checks = [];

$expect = function (string $name, bool $ok, string $detail = '') use (&$problems, &$checks): void {
    $checks[] = $name;

    if (!$ok) {
        $problems[] = $name . ($detail !== '' ? ' — ' . $detail : '');
    }
};

$settings = Settings::instance();
$settingKeys = [
    'plan_single_label',
    'plan_multi_label',
    'plan_extended_label',
    'plan_single_on',
    'plan_multi_on',
    'plan_extended_on',
];
$original = [];

foreach ($settingKeys as $key) {
    $original[$key] = $settings->getAttribute($key);
}

$labelsBefore = Catalog::planLabels();
$keysBefore = Catalog::planKeys();

try {
    $labels = Catalog::planLabels();

    $expect(
        'ключи тарифов неизменны',
        array_keys($labels) === ['single', 'multi', 'extended'],
        implode(', ', array_keys($labels))
    );

    foreach (['single' => 'Один сайт', 'multi' => 'До 5 сайтов', 'extended' => 'Без ограничений'] as $key => $default) {
        $stored = Settings::get('plan_' . $key . '_label');
        $expected = $stored ?: $default;

        $expect(
            'подпись ' . $key . ' берётся из настроек',
            $labels[$key] === $expected,
            '«' . $labels[$key] . '» вместо «' . $expected . '»'
        );
    }

    $planKeys = Catalog::planKeys();
    $plans = Catalog::plans();

    $expect(
        'по умолчанию продаются все три тарифа',
        $planKeys === ['single', 'multi', 'extended'],
        implode(', ', $planKeys)
    );

    $expect(
        'витрина совпадает с включёнными тарифами',
        array_column($plans, 'key') === $planKeys,
        implode(', ', array_column($plans, 'key'))
    );

    $settings->plan_multi_label = 'Бизнес-5';

    $expect(
        'переименование видно в plans()',
        in_array('Бизнес-5', array_column(Catalog::plans(), 'label'), true),
        implode(', ', array_column(Catalog::plans(), 'label'))
    );

    $expect(
        'переименование видно в Product::planLabel',
        (new Product())->planLabel('multi') === 'Бизнес-5',
        (new Product())->planLabel('multi')
    );

    $expect(
        'переименование видно в Price::plan_label',
        (new Price(['plan' => 'multi']))->plan_label === 'Бизнес-5',
        (new Price(['plan' => 'multi']))->plan_label
    );

    $expect(
        'цены в админке показывают новые подписи',
        (new Price())->getPlanOptions()['multi'] === 'Бизнес-5',
        (string) ((new Price())->getPlanOptions()['multi'] ?? 'нет')
    );

    $order = new Order(['plan' => 'multi']);

    $expect('заказ показывает текущую подпись', $order->plan_label === 'Бизнес-5', $order->plan_label);

    $order->meta = ['plan_label' => 'Тариф на момент покупки'];

    $expect(
        'старый заказ сохраняет свою подпись',
        $order->plan_label === 'Тариф на момент покупки',
        $order->plan_label
    );

    $settings->plan_extended_on = false;

    $expect(
        'выключенный тариф уходит с витрины',
        Catalog::planKeys() === ['single', 'multi'],
        implode(', ', Catalog::planKeys())
    );

    $expect(
        'выключенный тариф не попадает в plans()',
        !in_array('extended', array_column(Catalog::plans(), 'key'), true),
        implode(', ', array_column(Catalog::plans(), 'key'))
    );

    $misaligned = array_filter(
        Catalog::comparison(),
        fn (array $row): bool => array_keys($row['values']) !== ['single', 'multi', 'extended']
    );

    $expect(
        'сравнение привязано к ключам, колонки не съезжают',
        $misaligned === [],
        implode(', ', array_column($misaligned, 'name'))
    );

    $recommended = array_column(Calculator::recommendations(), 'plan');

    $expect(
        'калькулятор не рекомендует выключенный тариф',
        !in_array('extended', $recommended, true),
        implode(', ', $recommended)
    );

    $rules = new ReflectionMethod(OrderController::class, 'rules');
    $rules->setAccessible(true);
    $planRule = (string) ($rules->invoke(new OrderController())['plan'] ?? '');

    $expect(
        'форма принимает только известные ключи',
        $planRule === 'required|in:single,multi,extended',
        $planRule
    );
} finally {
    foreach ($original as $key => $value) {
        $settings->setAttribute($key, $value);
    }
}

$expect(
    'после проверки подписи как были',
    Catalog::planLabels() === $labelsBefore,
    implode(', ', Catalog::planLabels())
);

$expect(
    'после проверки набор тарифов как был',
    Catalog::planKeys() === $keysBefore,
    implode(', ', Catalog::planKeys())
);

echo json_encode([
    'problems' => array_values(array_filter($problems)),
    'checks' => count($checks),
    'labels' => Catalog::planLabels(),
    'keys' => Catalog::planKeys(),
    'plans' => array_column(Catalog::plans(), 'label'),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

exit($problems ? 1 : 0);
