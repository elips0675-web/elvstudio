<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Carbon\Carbon;
use Elv\Kit\Classes\AccountService;
use Elv\Kit\Classes\Http\TelegramController;
use Elv\Kit\Classes\SecurityHeaders;
use Elv\Kit\Classes\Support;
use Elv\Kit\Classes\Telegram;
use Elv\Kit\Classes\TicketFiles;
use Elv\Kit\Classes\TicketService;
use Elv\Kit\Models\Customer;
use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Ticket;
use Elv\Kit\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * tickets-check — обращения, SLA по рабочим часам, права кабинета и ChatOps.
 *
 * Проверка не ходит в сеть: Telegram выключен пустым токеном, почта уходит в
 * array-транспорт. Всё, что создаётся, удаляется в конце.
 */

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

// Почта не должна уходить в SMTP: очередь синхронная, транспорт — массив.
config(['mail.default' => 'array']);

$at = function ($moment) {
    return Support::dueAt($moment)->setTimezone(Support::TIMEZONE)->format('Y-m-d H:i');
};

$check($at('2026-11-27 17:30') === '2026-12-02 17:30', 'SLA from Friday 17:30 is not Wednesday 17:30: ' . $at('2026-11-27 17:30'));
$check($at('2026-11-28 12:00') === '2026-12-02 18:00', 'SLA from Saturday is not Wednesday 18:00: ' . $at('2026-11-28 12:00'));
$check($at('2026-11-30 09:00') === '2026-12-02 18:00', 'SLA from Monday morning is not Wednesday 18:00: ' . $at('2026-11-30 09:00'));
$check($at('2026-11-27 18:30') === '2026-12-02 18:00', 'SLA from Friday evening is not Wednesday 18:00: ' . $at('2026-11-27 18:30'));

$check(Support::isWorkingMoment(Carbon::parse('2026-11-27 17:59', Support::TIMEZONE)), 'Friday 17:59 is not a working moment');
$check(!Support::isWorkingMoment(Carbon::parse('2026-11-27 18:00', Support::TIMEZONE)), 'Friday 18:00 is counted as working time');
$check(!Support::isWorkingMoment(Carbon::parse('2026-11-28 12:00', Support::TIMEZONE)), 'Saturday is counted as working time');
$check(Support::SLA_HOURS === 24, 'SLA is not 24 working hours');

$suffix = bin2hex(random_bytes(4));
$email = 'qa-ticket-' . $suffix . '@example.test';
$otherEmail = 'qa-ticket-other-' . $suffix . '@example.test';

$customer = Customer::create([
    'email' => $email,
    'name' => 'Мария Сергеевна',
]);
$other = Customer::create(['email' => $otherEmail]);

$service = new TicketService();

$ticket = $service->open($customer, [
    'subject' => 'Не активируется ключ',
    'body' => 'Ввожу ключ на странице активации, получаю ошибку 403.',
]);

$check($ticket instanceof Ticket, 'ticket is not created');
$check((string) $ticket->number === 'TK-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), 'ticket number is not TK-000000');
$check((string) $ticket->status === Ticket::STATUS_NEW, 'new ticket is not in «new» status');
$check($ticket->sla_due_at !== null, 'new ticket has no SLA due date');
$check($ticket->first_response_at === null, 'new ticket already has a first response');
$check($ticket->messages()->count() === 1, 'new ticket does not keep the first message');
$check($ticket->messages()->first()->author === Ticket::AUTHOR_CUSTOMER, 'first message is not from the customer');
$check(str_contains((string) $ticket->url, '/tickets/?id=' . $ticket->id), 'ticket url is not canonical');
$check($ticket->sla_label !== '', 'ticket has no SLA label');
$check(str_contains($ticket->sla_label, 'Первый ответ до:'), 'open ticket SLA label does not show the deadline');

$ticket->forceFill(['sla_due_at' => now()->subHour()])->save();
$check(Support::isOverdue($ticket->fresh()), 'overdue ticket is not detected');
$check($ticket->fresh()->sla_overdue === true, 'overdue flag is not exposed to templates');

$service->customerReply($ticket->fresh(), 'Добавил скриншот ошибки.', $customer->display_name);
$ticket = $ticket->fresh();
$check((string) $ticket->status === Ticket::STATUS_OPEN, 'customer reply does not move the ticket to «open»');
$check($ticket->messages()->count() === 2, 'customer reply is not saved');
$check($ticket->messages()->get()->last()->author === Ticket::AUTHOR_CUSTOMER, 'customer reply has the wrong author');

$service->supportReply($ticket, 'Проверьте, что домен указан без https://', 'Иван');
$ticket = $ticket->fresh();
$check((string) $ticket->status === Ticket::STATUS_WAITING, 'support reply does not move the ticket to «waiting»');
$check($ticket->first_response_at !== null, 'support reply does not close the first response SLA');
$check($ticket->sla_overdue === false, 'answered ticket is still marked overdue');
$check(str_contains($ticket->sla_label, 'Первый ответ:'), 'answered ticket SLA label does not show the response time');
$check($ticket->messages()->get()->last()->author === Ticket::AUTHOR_SUPPORT, 'support reply has the wrong author');

$service->close($ticket);
$ticket = $ticket->fresh();
$check((string) $ticket->status === Ticket::STATUS_CLOSED, 'close does not set the «closed» status');
$check($ticket->closed_at !== null, 'close does not save the date');
$check($ticket->is_closed === true, 'closed ticket is not reported as closed');
$check($ticket->messages()->get()->last()->author === Ticket::AUTHOR_SYSTEM, 'close does not add a system message');
$check(Support::isOverdue($ticket) === false, 'closed ticket is marked overdue');

$before = $ticket->messages()->count();
$service->close($ticket);
$check($ticket->fresh()->messages()->count() === $before, 'second close adds a duplicate system message');

$service->customerReply($ticket->fresh(), 'Вопрос решился, но появился новый.', $customer->display_name);
$ticket = $ticket->fresh();
$check((string) $ticket->status === Ticket::STATUS_OPEN, 'reply to a closed ticket does not reopen it');
$check($ticket->closed_at === null, 'reopened ticket keeps the close date');

$account = new AccountService();
$check($account->ticket($customer, (int) $ticket->id) !== null, 'owner cannot open the ticket');
$check($account->ticket($other, (int) $ticket->id) === null, 'another customer opens the ticket');
$check($account->tickets($customer)->count() === 1, 'cabinet does not list the customer ticket');
$check($account->tickets($other)->count() === 0, 'another customer sees the ticket');
$check($account->ticket($customer, 0) === null, 'zero id returns a ticket');

$emailTicket = new Ticket();
$emailTicket->fill([
    'customer_id' => null,
    'email' => $email,
    'subject' => 'Письмо с другого адреса',
    'status' => Ticket::STATUS_NEW,
    'priority' => Ticket::PRIORITY_NORMAL,
]);
$emailTicket->save();
$check($account->ticket($customer, (int) $emailTicket->id) !== null, 'ticket found by e-mail is not visible to the customer');
$check($account->ticket($other, (int) $emailTicket->id) === null, 'ticket found by e-mail is visible to another customer');

// Обращение из формы обратной связи: покупатель без входа.
$guestEmail = 'qa-guest-' . $suffix . '@example.test';

$guestTicket = $service->openGuest([
    'email' => mb_strtoupper($guestEmail),
    'name' => 'Гость',
    'subject' => 'Не приходит письмо',
    'body' => 'Оплатил заказ, письмо с архивом не пришло.',
]);

$check($guestTicket instanceof Ticket, 'guest ticket is not created');
$guestCustomer = Customer::query()->where('email', $guestEmail)->first();
$check($guestCustomer !== null, 'guest ticket does not create a customer for Magic Link');
$check((int) $guestTicket->customer_id === (int) ($guestCustomer->id ?? 0), 'guest ticket is not linked to the created customer');
$check($account->ticket($guestCustomer, (int) $guestTicket->id) !== null, 'guest ticket is not visible in the cabinet');
$check((string) $guestTicket->email === $guestEmail, 'guest ticket e-mail is not normalized');
$check((string) $guestTicket->source === 'feedback', 'guest ticket source is not feedback');
$check($guestTicket->source_label === 'Форма обратной связи', 'guest ticket source label is wrong');
$check($guestTicket->messages()->count() === 1, 'guest ticket has no first message');
$check((string) $guestTicket->messages()->first()->author_name === 'Гость', 'guest ticket loses the author name');

$linked = $service->openGuest([
    'email' => $email,
    'name' => 'Мария',
    'subject' => 'Повторный вопрос',
    'body' => 'Уточняю по тому же заказу.',
]);

$check((int) $linked->customer_id === (int) $customer->id, 'guest ticket is not linked to a known customer');

// Консультант передаёт переписку оператору: история и автор «Консультант».
$handoff = $service->handoff([
    'email' => $email,
    'name' => 'Мария',
    'message' => 'Хочу уточнить по лицензии.',
    'history' => [
        ['role' => 'user', 'text' => 'Сколько доменов входит в тариф?'],
        ['role' => 'bot', 'text' => 'В тарифе multi — до пяти доменов.'],
        ['role' => 'user', 'text' => '   '],
    ],
]);

$check((string) $handoff->source === 'chat', 'chat handoff source is not chat');
$check((int) $handoff->customer_id === (int) $customer->id, 'chat handoff is not linked to the customer');
$check($handoff->messages()->count() === 3, 'chat handoff keeps ' . $handoff->messages()->count() . ' messages instead of 3');
$check(
    $handoff->messages()->orderBy('id')->pluck('author')->all() === [Ticket::AUTHOR_CUSTOMER, Ticket::AUTHOR_BOT, Ticket::AUTHOR_CUSTOMER],
    'chat handoff authors are wrong'
);
$check(str_contains((string) $handoff->subject, 'Сколько доменов'), 'chat handoff subject is not the first question');
$check($handoff->fresh()->lastMessage !== null, 'last message accessor returns nothing');

$bot = $handoff->messages()->where('author', Ticket::AUTHOR_BOT)->first();
$check($bot !== null && $bot->author_label === 'Консультант', 'bot message has the wrong label');
$check($bot !== null && $bot->is_bot === true, 'bot message is not flagged as bot');

// Быстрые ответы оператора: «Название | Текст», мусор пропускается.
$originalReplies = Settings::get('ticket_quick_replies');
Settings::set('ticket_quick_replies', "Приветствие | Здравствуйте! Уже смотрим вопрос.\nБез разделителя\nСсылка | Гайд: /docs/\n");

$replies = TicketService::quickReplies();
$check(count($replies) === 2, 'quick replies parser returns ' . count($replies) . ' items instead of 2');
$check(($replies[0]['title'] ?? '') === 'Приветствие', 'quick reply title is wrong');
$check(($replies[1]['body'] ?? '') === 'Гайд: /docs/', 'quick reply body is wrong');

Settings::set('ticket_quick_replies', (string) $originalReplies);

// Вложения: белый список, случайное имя на диске, ссылка через маршрут.
$originalAttachments = Settings::get('ticket_attachments_enabled');
Settings::set('ticket_attachments_enabled', true);

$check(TicketFiles::enabled() === true, 'attachments are not enabled by settings');

Settings::set('ticket_attachments_enabled', '');
$check(TicketFiles::enabled() === true, 'empty attachment setting must default to enabled');
Settings::set('ticket_attachments_enabled', true);
$check(TicketFiles::MAX_FILES === 3, 'attachment limit is not 3 files');
$check(TicketFiles::sizeLabel(2048) === '2 КБ', 'size label for 2 KB is wrong: ' . TicketFiles::sizeLabel(2048));
$check(TicketFiles::sizeLabel(1572864) === '1.5 МБ', 'size label for 1.5 MB is wrong: ' . TicketFiles::sizeLabel(1572864));

$fileRules = TicketFiles::rules();
$check(($fileRules['attachments'] ?? '') === 'array|max:3', 'attachment count rule is wrong');
$check(str_contains((string) ($fileRules['attachments.*'] ?? ''), 'mimes:'), 'attachment type rule is missing');

$files = new TicketFiles();
$upload = UploadedFile::fake()->createWithContent('Скриншот ошибки.txt', str_repeat('a', 4096));
$stored = $files->store($guestTicket, [$upload]);

$check(count($stored) === 1, 'attachment is not stored');
$check(isset($stored[0]['file']) && preg_match('/^[A-Za-z0-9]{32}\.txt$/', (string) $stored[0]['file']) === 1, 'stored attachment name is not random');
$check(($stored[0]['name'] ?? '') === 'Скриншот ошибки.txt', 'attachment does not keep the original name');
$check(($stored[0]['size'] ?? 0) === 4096, 'attachment size is not saved');
$check($files->path((int) $guestTicket->id, (string) $stored[0]['file']) !== null, 'stored attachment is not found on disk');
$check($files->path((int) $guestTicket->id, '../../evil.txt') === null, 'attachment path traversal is not blocked');

$guestTicket->messages()->first()->forceFill(['attachments' => $stored])->save();
$firstMessage = $guestTicket->fresh()->messages()->first();

$check(count($firstMessage->files) === 1, 'attachment metadata is not exposed to templates');
$check(str_contains((string) $firstMessage->files[0]['url'], '/tickets/' . $guestTicket->id . '/file/'), 'attachment url is not a guarded route');
$check(($firstMessage->files[0]['size_label'] ?? '') === '4 КБ', 'attachment size label is wrong: ' . ($firstMessage->files[0]['size_label'] ?? ''));

$rejected = $files->store($guestTicket, [UploadedFile::fake()->create('script.php', 1, 'text/x-php')]);
$check($rejected === [], 'attachment whitelist accepts php');

$mismatch = UploadedFile::fake()
    ->createWithContent('payload.txt', '<script>alert(1)</script>')
    ->mimeType('text/html');
$check($files->store($guestTicket, [$mismatch]) === [], 'attachment MIME whitelist accepts text/html in a txt file');

Settings::set('ticket_attachments_enabled', false);
$check(TicketFiles::enabled() === false, 'attachments stay enabled after disabling');
$check($files->store($guestTicket, [UploadedFile::fake()->create('ok.txt', 1, 'text/plain')]) === [], 'disabled attachments are still stored');

Settings::set('ticket_attachments_enabled', (string) $originalAttachments);

// Автозакрытие: только «ждём ответа», где последнее слово не за покупателем.
DB::beginTransaction();

try {
    $stale = $service->open($customer, [
        'subject' => 'Давно ждёт ответа',
        'body' => 'Подскажите, пожалуйста, по установке.',
    ]);
    $service->supportReply($stale, 'Ответили и ждём подтверждения.', 'Иван');
    Ticket::query()->whereKey($stale->id)->update(['updated_at' => now()->subDays(10)]);

    $active = $service->open($customer, [
        'subject' => 'Последнее слово за покупателем',
        'body' => 'Уточняю детали перед ответом.',
    ]);
    $service->supportReply($active, 'Ответ поддержки.', 'Иван');
    $service->customerReply($active->fresh(), 'Спасибо, проверю.', $customer->display_name);
    Ticket::query()->whereKey($active->id)->update(['updated_at' => now()->subDays(10)]);

    $check((new TicketService())->autocloseCount(5) >= 1, 'autoclose does not see the stale ticket');
    $check((new TicketService())->autoclose(5) >= 1, 'autoclose closed nothing');
    $check((string) $stale->fresh()->status === Ticket::STATUS_CLOSED, 'autoclose does not close a waiting ticket');
    $check((string) $active->fresh()->status === Ticket::STATUS_OPEN, 'autoclose closes a ticket where the customer spoke last');
    $check((new TicketService())->autoclose(0) === 0, 'autoclose with zero days closes tickets');
}
finally {
    DB::rollBack();
}

// Маршруты поддержки: передача из чата, polling и скачивание вложения.
$check(Route::has('elv.kit.chat.handoff'), 'chat handoff route is missing');
$check(Route::has('elv.kit.ticket.thread'), 'ticket thread route is missing');
$check(Route::has('elv.kit.ticket.file'), 'ticket file route is missing');

$threadRoute = Route::getRoutes()->getByName('elv.kit.ticket.thread');
$check($threadRoute !== null && str_contains($threadRoute->uri(), '{id}/thread'), 'thread route uri is wrong');

$fileRoute = Route::getRoutes()->getByName('elv.kit.ticket.file');
$check($fileRoute !== null && str_contains($fileRoute->uri(), '{id}/file/{name}'), 'file route uri is wrong');
$check($fileRoute !== null && in_array('GET', $fileRoute->methods(), true), 'file route does not answer GET');


$check(View::exists('elv.kit::mail.ticket-admin'), 'ticket admin mail view is missing');
$check(View::exists('elv.kit::mail.ticket-customer'), 'ticket customer mail view is missing');

// Telegram выключен: пустой токен и chat_id не должны ничего отправлять.
$originalToken = Settings::get('telegram_bot_token');
$originalChat = Settings::get('telegram_chat_id');
$originalSecret = Settings::get('telegram_webhook_secret');

Settings::set('telegram_bot_token', '');
Settings::set('telegram_chat_id', '');
Settings::set('telegram_webhook_secret', '');

$check(Telegram::enabled() === false, 'Telegram is enabled without a token');
$check(Telegram::send('test') === false, 'Telegram sends with an empty token');
$check(Telegram::webhookUrl() === '', 'webhook url exists without a secret');
$check(Telegram::secretMatches('anything') === false, 'empty secret matches any value');
$check(Telegram::chatMatches('123') === false, 'empty chat id matches any chat');

$route = Route::getRoutes()->getByName('elv.kit.telegram.webhook');
$check($route !== null, 'telegram webhook route is missing');
$check($route && str_contains($route->uri(), '{secret}'), 'webhook route has no secret segment');
$check($route && in_array('elv.kit.ticket.store', array_keys(Route::getRoutes()->getRoutesByName())), 'ticket store route is missing');

$controller = new TelegramController();

try {
    $controller->webhook(Request::create('/telegram/webhook/' . str_repeat('a', 20), 'POST'), str_repeat('a', 20));
    $check(false, 'webhook accepts requests without a configured secret');
}
catch (HttpException $e) {
    $check($e->getStatusCode() === 404, 'webhook answers ' . $e->getStatusCode() . ' instead of 404');
}

// Настроенный секрет и чат: команда /reply отвечает покупателю без сети.
$secret = 'qa' . $suffix . str_repeat('0', 10);
$chatId = '900' . random_int(100000, 999999);
Settings::set('telegram_webhook_secret', $secret);
Settings::set('telegram_chat_id', $chatId);

$check(Telegram::secretMatches($secret), 'configured webhook secret does not match');
$check(!Telegram::secretMatches($secret . 'x'), 'wrong webhook secret matches');
$check(Telegram::chatMatches($chatId), 'configured chat id does not match');
$check(!Telegram::chatMatches('1'), 'foreign chat id matches');
$check(Telegram::webhookUrl() === url('/telegram/webhook/' . $secret), 'webhook url is not built from the secret');

$replyTicket = $service->open($customer, [
    'subject' => 'Вопрос по установке',
    'body' => 'Куда положить файлы темы?',
]);

$update = [
    'message' => [
        'chat' => ['id' => (int) $chatId],
        'from' => ['first_name' => 'Оператор'],
        'text' => '/reply ' . $replyTicket->number . ' Файлы темы — в themes/.',
    ],
];

$controller->webhook(Request::create('/telegram/webhook/' . $secret, 'POST', [], [], [], [], json_encode($update)), $secret);
$replyTicket = $replyTicket->fresh();
$check((string) $replyTicket->status === Ticket::STATUS_WAITING, 'webhook /reply does not answer the ticket');
$check($replyTicket->first_response_at !== null, 'webhook /reply does not close the SLA');
$check($replyTicket->messages()->get()->last()->author_name === 'Оператор', 'webhook /reply loses the operator name');

$foreign = $update;
$foreign['message']['chat']['id'] = 1;
$foreign['message']['text'] = '/close ' . $replyTicket->number;
$controller->webhook(Request::create('/telegram/webhook/' . $secret, 'POST', [], [], [], [], json_encode($foreign)), $secret);
$check((string) $replyTicket->fresh()->status === Ticket::STATUS_WAITING, 'webhook accepts commands from a foreign chat');

// Заголовки: кабинет и поддержка закрыты от индексации и кэша.
$headers = (new SecurityHeaders())->handle(Request::create('/tickets/'), function () {
    return response('ok');
});

$check((string) $headers->headers->get('X-Robots-Tag') === 'noindex, nofollow', '/tickets/ is not closed from indexing');
$check(str_contains((string) $headers->headers->get('Cache-Control'), 'no-store'), '/tickets/ is cacheable');

$accountHeaders = (new SecurityHeaders())->handle(Request::create('/account/'), function () {
    return response('ok');
});

$check((string) $accountHeaders->headers->get('X-Robots-Tag') === 'noindex, nofollow', '/account/ is not closed from indexing');

$check((string) TicketMessage::query()->where('ticket_id', $ticket->id)->first()->author === Ticket::AUTHOR_CUSTOMER, 'message author is not saved');

Settings::set('telegram_bot_token', (string) $originalToken);
Settings::set('telegram_chat_id', (string) $originalChat);
Settings::set('telegram_webhook_secret', (string) $originalSecret);

$ticketIds = Ticket::query()->whereIn('email', [$email, $otherEmail, $guestEmail])->pluck('id')->all();
TicketMessage::query()->whereIn('ticket_id', $ticketIds)->delete();
Ticket::query()->whereIn('id', $ticketIds)->delete();
Customer::query()->whereIn('email', [$email, $otherEmail, $guestEmail])->delete();

foreach ($ticketIds as $ticketId) {
    $dir = storage_path('app/elv-kit/tickets/' . (int) $ticketId);

    if (!is_dir($dir)) {
        continue;
    }

    foreach ((array) glob($dir . '/*') as $path) {
        @unlink($path);
    }

    @rmdir($dir);
}

if ($errors !== []) {
    fwrite(STDERR, "tickets checks failed:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo "OK: tickets checks passed\n";
