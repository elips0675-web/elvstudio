<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\Chat;
use Elv\Kit\Classes\TextFilter;
use Elv\Kit\Models\Settings;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

foreach (['хуй', 'х у й', 'xyй', 'пиззда', 'fuck', 'п1зд', '3бал', 'д0лбоеб', 'дро4', 'хуйня'] as $word) {
    $check(TextFilter::has($word), 'filter misses: ' . $word);
}

foreach (['хутор', 'нормальный вопрос', 'установка', 'х0р', 'п1тон', 'эпидраздел'] as $word) {
    $check(!TextFilter::has($word), 'filter false positive: ' . $word);
}

foreach (['shitake', 'dickens', 'pussycat', 'cocktail'] as $word) {
    $check(!TextFilter::has($word), 'filter latin false positive: ' . $word);
}

foreach (['fuck', 'fucking', 'bitches', 'dicks', 'xyй', 'х у й'] as $word) {
    $check(TextFilter::has($word), 'filter latin/cyrillic miss: ' . $word);
}

$refusal = Chat::answer('хуй');
$check($refusal['answer'] === TextFilter::REFUSAL, 'profanity refusal is not returned');
$check($refusal['sources'] === [], 'profanity refusal must not expose search sources');
$check($refusal['rejected'] === true, 'profanity message must be rejected');
$check(($refusal['hide_user'] ?? false) === true, 'profanity must hide the user bubble');

foreach (['абырвалг', 'фывап', 'абырвалг абырвалг', 'абырвалг оплата', 'фывап тарифы'] as $nonsense) {
    $reply = Chat::answer($nonsense);
    $check($reply['rejected'] === true, 'nonsense message is not rejected: ' . $nonsense);
    $check(str_contains($reply['answer'], 'Не понял'), 'nonsense answer is missing: ' . $nonsense);
    $check($reply['sources'] === [], 'nonsense answer must not expose sources: ' . $nonsense);
    $check(($reply['hide_user'] ?? false) === false, 'nonsense must keep the user bubble: ' . $nonsense);
}

foreach (['nginx', 'webhook', 'stripe', 'телеграм', 'вебхук', 'как настроить nginx', 'stripe оплата'] as $term) {
    $reply = Chat::answer($term);
    $check($reply['rejected'] === false, 'technical term is rejected: ' . $term);
    $check(($reply['hide_user'] ?? false) === false, 'technical term hides the user bubble: ' . $term);
}

$telegram = Chat::answer('телеграм');
$check(
    mb_stripos($telegram['answer'], 'e-mail') !== false
    || mb_stripos($telegram['answer'], 'telegram') !== false
    || mb_stripos($telegram['answer'], 'почт') !== false,
    'telegram request does not return contacts'
);

$crowdfunding = Chat::answer('краудфандинг');
$check($crowdfunding['rejected'] === false, 'known off-site term is rejected: краудфандинг');

$empty = Chat::answer('');
$check(str_contains($empty['answer'], 'подробнее'), 'empty message does not ask for details');

$long = Chat::answer(str_repeat('а', 10000));
$check(is_array($long) && $long['rejected'] === true, 'long nonsense is not rejected');

$settingsModel = Settings::instance();
$servicesBackup = $settingsModel->getAttribute('chat_services');
$servicesUrlBackup = $settingsModel->getAttribute('chat_services_url');

$settingsModel->forceFill(['chat_services' => '', 'chat_services_url' => '']);

$neutral = Chat::answer('какие услуги вы оказываете');
$check(!str_contains($neutral['answer'], 'Elv Studio'), 'empty services still advertise the studio');
$check(($neutral['link'] ?? null) === null, 'empty services must not add a link');
$check(str_contains($neutral['answer'], 'обратной связи'), 'empty services do not point to feedback');
$check(!str_contains($neutral['answer'], 'В демо-записи'), 'services answer mixes booking services');

$neutralAudit = Chat::answer('аудит сайта');
$check(($neutralAudit['link'] ?? null) === null, 'audit without configured services must not link');

$settingsModel->forceFill(['chat_services' => "Стрижка\nМаникюр", 'chat_services_url' => '/uslugi/']);

$custom = Chat::answer('какие услуги вы оказываете');
$check(str_contains($custom['answer'], 'Стрижка') && str_contains($custom['answer'], 'Маникюр'), 'configured services are not listed');
$check(str_contains((string) ($custom['link']['url'] ?? ''), '/uslugi'), 'configured services link is not used');
$check(str_ends_with((string) ($custom['link']['url'] ?? ''), '/'), 'configured services link lost the trailing slash');

$settingsModel->forceFill(['chat_services' => "Стрижка\nМаникюр\nаудит"]);
$audit = Chat::answer('аудит сайта');
$check(str_contains((string) ($audit['link']['url'] ?? ''), '/uslugi'), 'audit link is missing when audit is listed');
$check(str_contains($audit['answer'], 'аудит'), 'audit answer is missing');

$settingsModel->forceFill(['chat_services' => $servicesBackup, 'chat_services_url' => $servicesUrlBackup]);

$booking = Chat::answer('записаться на услугу');
$check(str_contains($booking['answer'], 'Онлайн-запись'), 'booking answer is missing');

$contact = Chat::answer('связаться с вами');
$check($contact['rejected'] === false, 'contact request is rejected');
$check(!str_contains($contact['answer'], 'Не понял'), 'contact request falls into nonsense');

$setup = Chat::answer('как настроить сайт');
$check(str_contains($setup['answer'], 'Настройка сайта'), 'site setup answer is missing');

$pricing = Chat::answer('сколько стоит');
$check(str_contains($pricing['answer'], 'Тарифы Elv Kit'), 'pricing answer is missing');
$check(($pricing['link']['label'] ?? '') === 'Сравнить тарифы', 'pricing link is missing');
$check($pricing['sources'] === [], 'pricing answer must not add unrelated sources');
$check($pricing['rejected'] === false, 'valid pricing question must not be rejected');

$search = Chat::answer('найди статью про оплату');
$check(str_contains($search['answer'], 'Нашёл материалы'), 'explicit search answer is missing');
$check(count($search['sources']) >= 1, 'explicit search returns no sources');
$check(count($search['sources']) <= 3, 'explicit search returns more than three sources');
$check($search['rejected'] === false, 'valid search must not be rejected');

$keep = Chat::answer('как перестать получать письма');
$check(!str_contains($keep['answer'], 'Нашёл материалы'), 'word «перестать» is mistaken for a request about articles');
$check(!str_contains($keep['answer'], 'Блог входит'), 'word «перестать» is mistaken for a request about the blog');

$bundle = Chat::answer('покажи связку модулей');
$check(
    str_contains($bundle['answer'], 'Раздел') || str_contains($bundle['answer'], 'Модул'),
    'word «связка» is mistaken for a request to get in touch'
);

$urls = [];

foreach ($search['sources'] as $source) {
    $check(str_starts_with((string) ($source['url'] ?? ''), 'http'), 'source url is not absolute: ' . ($source['label'] ?? ''));
    $check(in_array($source['kind'] ?? '', ['Статья', 'Страница'], true), 'source kind is unknown: ' . ($source['kind'] ?? ''));
    $urls[] = $source['url'] ?? '';
}

$check(count($urls) === count(array_unique($urls)), 'search sources contain duplicates');

$fallback = Chat::answer('как настроить CRM');
$check(str_contains($fallback['answer'], 'Точного ответа нет'), 'fallback did not use site search');
$check(count($fallback['sources']) >= 1, 'fallback search returns no sources');
$check($fallback['rejected'] === false, 'fallback with sources must not be rejected');

$nothing = Chat::answer('найди абракадабру без совпадений');
$check(str_contains($nothing['answer'], 'ничего не нашёл'), 'empty search does not report the miss');
$check($nothing['open'] === 'feedback', 'empty search must offer feedback');
$check($nothing['sources'] === [], 'empty search must not expose sources');

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: chat checks passed' . PHP_EOL;
