<?php

/**
 * presets-check — пресеты ниш: файлы, ключи и запись настроек.
 *
 * Проверка идёт в транзакции с откатом: реальные настройки стенда не меняются.
 * Запуск: php tools/presets-check.php
 */

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Demo;
use Elv\Kit\Classes\Faq;
use Elv\Kit\Classes\Icons;
use Elv\Kit\Classes\Slots;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\DB;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$expectedPresets = ['booking', 'digital', 'agency', 'expert'];
$check(Demo::availablePresets() === $expectedPresets, 'список пресетов изменился: ' . implode(', ', Demo::availablePresets()));
$check(Demo::PRESET_SETTINGS !== [], 'список настроек пресета пуст');

$iconPaths = (new ReflectionClass(Icons::class))->getReflectionConstant('PATHS')->getValue();
$presetValues = [];

foreach (Demo::availablePresets() as $preset) {
    $check(Demo::presetExists($preset), 'нет файла пресета «' . $preset . '»');
    $check(Demo::presetExists('../' . $preset) === false, 'пресет принимает путь вместо имени: ' . $preset);

    $data = Demo::presetData($preset);
    $settings = (array) ($data['settings'] ?? []);

    $check(($data['title'] ?? '') !== '', 'у пресета «' . $preset . '» нет названия');
    $missing = array_values(array_diff(Demo::PRESET_SETTINGS, array_keys($settings)));
    $extra = array_values(array_diff(array_keys($settings), Demo::PRESET_SETTINGS));
    $check($missing === [] && $extra === [], 'набор ключей пресета «' . $preset . '» не совпадает с PRESET_SETTINGS'
        . ($missing !== [] ? '; нет: ' . implode(', ', $missing) : '')
        . ($extra !== [] ? '; лишние: ' . implode(', ', $extra) : ''));

    foreach (['brand_name', 'brand_tagline', 'brand_description', 'brand_subtitle', 'legal_name', 'phone', 'chat_services', 'chat_services_url', 'booking_services'] as $key) {
        $check(trim((string) ($settings[$key] ?? '')) !== '', 'пресет «' . $preset . '»: пустое поле ' . $key);
    }

    $check(filter_var((string) ($settings['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false, 'пресет «' . $preset . '»: некорректный e-mail');
    $check(str_starts_with((string) ($settings['chat_services_url'] ?? ''), '/'), 'пресет «' . $preset . '»: ссылка услуг должна начинаться со слэша');

    $check(count(preg_split('/\R/u', (string) $settings['chat_services']) ?: []) >= 3, 'пресет «' . $preset . '»: услуг для консультанта меньше трёх');

    foreach (preg_split('/\R/u', (string) $settings['menu_products']) ?: [] as $line) {
        $parts = array_map('trim', explode('|', $line));
        $check(count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '', 'пресет «' . $preset . '»: строка меню без названия или адреса — «' . $line . '»');
    }

    foreach (['faq_items', 'faq_calculator_items', 'faq_services_items', 'faq_seo_items'] as $key) {
        $items = Faq::parse((string) $settings[$key]);
        $check(count($items) >= 2, 'пресет «' . $preset . '»: в ' . $key . ' меньше двух пар «вопрос | ответ»');

        foreach ($items as $item) {
            $check($item['q'] !== '' && $item['a'] !== '', 'пресет «' . $preset . '»: пустой вопрос или ответ в ' . $key);
        }
    }

    $services = Slots::parseServices((string) $settings['booking_services']);
    $check(count($services) >= 3, 'пресет «' . $preset . '»: услуг записи меньше трёх');
    $codes = [];

    foreach ($services as $service) {
        $codes[] = $service['code'];
        $check(preg_match('/^[a-z0-9-]+$/', $service['code']) === 1, 'пресет «' . $preset . '»: код услуги не латиницей — ' . $service['code']);
        $check($service['duration'] >= 5 && $service['duration'] <= 480, 'пресет «' . $preset . '»: длительность вне 5–480 минут — ' . $service['code']);
        $check($service['price'] !== '', 'пресет «' . $preset . '»: у услуги нет цены — ' . $service['code']);
        $check($service['icon'] === '' || isset($iconPaths[$service['icon']]), 'пресет «' . $preset . '»: нет иконки — ' . $service['icon']);
    }

    $check(count($codes) === count(array_unique($codes)), 'пресет «' . $preset . '»: коды услуг повторяются');

    $values = Demo::presetValues($preset);
    $presetValues[$preset] = $values;

    $check(!str_contains(json_encode($values, JSON_UNESCAPED_UNICODE) ?: '', '{{currency}}'), 'пресет «' . $preset . '»: в настройках остался плейсхолдер {{currency}}');
    $check(($values['brand_name'] ?? '') === trim((string) $settings['brand_name']), 'presetValues теряет значение бренда');
}

/*
 * Запись настроек: пресеты переключаются без хвостов, сброс возвращает
 * демо-значения Elv Kit. Транзакция откатывается, настройки стенда целы.
 */
$backup = [];

foreach (Demo::PRESET_SETTINGS as $key) {
    $backup[$key] = Settings::get($key);
}

DB::beginTransaction();

try {
    foreach (Demo::availablePresets() as $preset) {
        Demo::applyPreset($preset);

        $check((string) Settings::get('brand_name') === $presetValues[$preset]['brand_name'], 'applyPreset не записал бренд «' . $preset . '»');
        $check(count(Slots::services()) >= 3, 'после пресета «' . $preset . '» услуги записи не читаются из настроек');
    }

    Demo::resetPresetSettings();

    $check((string) Settings::get('brand_name') === 'Elv Kit', 'сброс пресета не вернул бренд Elv Kit');
    $check((string) Settings::get('brand_subtitle') === '', 'сброс пресета не очистил подпись под логотипом');
    $check((string) Settings::get('booking_services') === '', 'сброс пресета не очистил услуги записи');
}
catch (Throwable $error) {
    $errors[] = 'исключение при записи пресетов: ' . $error->getMessage();
}
finally {
    DB::rollBack();
    Settings::clearInternalCache();
    (new Settings())->clearCache();
}

foreach ($backup as $key => $value) {
    $check((string) Settings::get($key) === (string) $value, 'после отката настройка «' . $key . '» не восстановилась');
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: пресетов ' . count($presetValues) . ', настроек в каждом ' . count(Demo::PRESET_SETTINGS) . ', запись и сброс работают' . PHP_EOL;
