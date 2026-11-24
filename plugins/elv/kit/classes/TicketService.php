<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\TicketAdmin;
use Elv\Kit\Classes\Mail\TicketCustomer;
use Elv\Kit\Models\Customer;
use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Ticket;
use Elv\Kit\Models\TicketMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * TicketService — создание обращений, ответы и уведомления.
 *
 * Обращение сначала сохраняется, потом ставятся письма и Telegram: если
 * внешний канал лежит, история остаётся в базе и видна в кабинете и админке.
 *
 * Три входа в поддержку дают одно обращение: кабинет, форма обратной связи и
 * кнопка «Передать оператору» в Консультанте. У формы и чата покупатель может
 * быть без входа — клиент создаётся по e-mail, чтобы обращение было видно в
 * кабинете после входа по Magic Link.
 */
class TicketService
{
    /**
     * open — обращение из кабинета: покупатель уже известен.
     *
     * @param  array{subject: string, body: string, order_id?: int|null, attachments?: array<int, array<string, mixed>>}  $data
     */
    public function open(Customer $customer, array $data): Ticket
    {
        return $this->create([
            'customer_id' => (int) $customer->id,
            'email' => (string) $customer->email,
            'name' => (string) $customer->display_name,
            'subject' => (string) $data['subject'],
            'body' => (string) $data['body'],
            'order_id' => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'source' => (string) ($data['source'] ?? 'account'),
            'attachments' => (array) ($data['attachments'] ?? []),
        ]);
    }

    /**
     * openGuest — обращение из формы обратной связи без входа.
     *
     * Клиент создаётся или находится по e-mail: покупатель входит по Magic Link
     * на тот же адрес и видит обращение в кабинете. Существующая карточка не
     * перезаписывается, пустое имя заполняется из формы.
     *
     * @param  array{email: string, name?: string, subject?: string, body: string, order_id?: int|null, attachments?: array<int, array<string, mixed>>}  $data
     */
    public function openGuest(array $data): Ticket
    {
        $email = mb_strtolower(trim((string) $data['email']));
        $name = trim((string) ($data['name'] ?? ''));

        // Обращение с формы должно быть видно в кабинете: покупатель входит по
        // Magic Link на этот же e-mail. Поэтому клиент создаётся, а не только
        // ищется; существующая карточка не перезаписывается.
        $customer = Customer::query()->firstOrCreate(['email' => $email], ['name' => $name]);

        if ($name !== '' && trim((string) $customer->name) === '') {
            $customer->forceFill(['name' => $name])->save();
        }

        return $this->create([
            'customer_id' => (int) $customer->id,
            'email' => $email,
            'name' => $name,
            'subject' => (string) ($data['subject'] ?? ''),
            'body' => (string) $data['body'],
            'order_id' => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'source' => 'feedback',
            'attachments' => (array) ($data['attachments'] ?? []),
        ]);
    }

    /**
     * handoff — обращение из Консультанта с историей переписки.
     *
     * История приходит списком `role` (`user`/`bot`) и `text`: ответы бота
     * сохраняются отдельным автором, чтобы поддержка видела, что уже сказал
     * консультант, и не отвечала то же самое.
     *
     * @param  array{email: string, name?: string, message?: string, history?: array<int, array{role?: string, text?: string}>}  $data
     */
    public function handoff(array $data): Ticket
    {
        $history = [];

        foreach (array_slice((array) ($data['history'] ?? []), -30) as $item) {
            $text = trim((string) (is_array($item) ? ($item['text'] ?? '') : ''));
            $role = (string) (is_array($item) ? ($item['role'] ?? 'user') : 'user');

            if ($text === '') {
                continue;
            }

            $history[] = [
                'author' => $role === 'bot' ? Ticket::AUTHOR_BOT : Ticket::AUTHOR_CUSTOMER,
                'body' => mb_substr($text, 0, 2000),
            ];
        }

        $message = trim((string) ($data['message'] ?? ''));

        if ($message !== '') {
            $history[] = ['author' => Ticket::AUTHOR_CUSTOMER, 'body' => mb_substr($message, 0, 2000)];
        }

        $first = '';

        foreach ($history as $item) {
            if ($item['author'] === Ticket::AUTHOR_CUSTOMER) {
                $first = $item['body'];
                break;
            }
        }

        $email = mb_strtolower(trim((string) $data['email']));
        $name = trim((string) ($data['name'] ?? ''));
        $customer = Customer::query()->firstOrCreate(['email' => $email], ['name' => $name]);

        if ($name !== '' && trim((string) $customer->name) === '') {
            $customer->forceFill(['name' => $name])->save();
        }

        return $this->create([
            'customer_id' => (int) $customer->id,
            'email' => $email,
            'name' => $name,
            'subject' => mb_substr($first !== '' ? $first : 'Вопрос из Консультанта', 0, 120),
            'body' => '',
            'history' => $history,
            'source' => 'chat',
        ]);
    }

    /**
     * customerReply — сообщение покупателя в существующем обращении.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     */
    public function customerReply(Ticket $ticket, string $body, string $name = '', array $attachments = []): TicketMessage
    {
        $message = $this->addMessage($ticket, Ticket::AUTHOR_CUSTOMER, $body, $name, $attachments);

        $ticket->status = Ticket::STATUS_OPEN;
        $ticket->closed_at = null;
        $ticket->save();

        $this->notifyAdmin($ticket);
        Telegram::notifyCustomerMessage($ticket, $message);

        return $message;
    }

    /**
     * supportReply — ответ поддержки. Первый ответ закрывает SLA.
     */
    public function supportReply(Ticket $ticket, string $body, string $authorName = 'Поддержка'): TicketMessage
    {
        $message = $this->addMessage($ticket, Ticket::AUTHOR_SUPPORT, $body, $authorName);

        if (!$ticket->first_response_at) {
            $ticket->first_response_at = now();
        }

        $ticket->status = Ticket::STATUS_WAITING;
        $ticket->closed_at = null;
        $ticket->save();

        $this->notifyCustomer($ticket, $message);

        return $message;
    }

    /**
     * close — обращение закрыто, в истории остаётся служебная запись.
     */
    public function close(Ticket $ticket, string $authorName = 'Поддержка', string $note = 'Обращение закрыто.'): void
    {
        if ($ticket->is_closed) {
            return;
        }

        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->closed_at = now();
        $ticket->save();

        $this->addMessage($ticket, Ticket::AUTHOR_SYSTEM, $note, $authorName);
    }

    /**
     * autoclose — закрывает обращения, где покупатель молчит после ответа.
     *
     * Считаются только обращения в статусе «Ждём ответа»: если последнее
     * сообщение написал покупатель, обращение в работе и закрывать его нельзя.
     */
    public function autoclose(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $closed = 0;
        $threshold = now()->subDays($days);

        Ticket::query()
            ->where('status', Ticket::STATUS_WAITING)
            ->where('updated_at', '<=', $threshold)
            ->with('messages')
            ->chunkById(100, function ($tickets) use (&$closed, $days) {
                foreach ($tickets as $ticket) {
                    $last = $ticket->messages->last();

                    if ($last && (string) $last->author === Ticket::AUTHOR_CUSTOMER) {
                        continue;
                    }

                    $this->close(
                        $ticket,
                        'Автозакрытие',
                        'Обращение закрыто автоматически: нет ответа покупателя ' . $days . ' дн.'
                    );

                    $closed++;
                }
            });

        return $closed;
    }

    /**
     * autocloseCount — сколько обращений попадёт под автозакрытие.
     */
    public function autocloseCount(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $count = 0;

        Ticket::query()
            ->where('status', Ticket::STATUS_WAITING)
            ->where('updated_at', '<=', now()->subDays($days))
            ->with('messages')
            ->chunkById(100, function ($tickets) use (&$count) {
                foreach ($tickets as $ticket) {
                    $last = $ticket->messages->last();

                    if ($last && (string) $last->author === Ticket::AUTHOR_CUSTOMER) {
                        continue;
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * quickReplies — шаблоны ответов оператора из настроек.
     *
     * Формат строки — «Название | Текст». Пустая настройка означает, что
     * шаблонов нет: кнопки в админке не показываются.
     *
     * @return array<int, array{title: string, body: string}>
     */
    public static function quickReplies(): array
    {
        $raw = (string) Settings::get('ticket_quick_replies', '');
        $items = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !str_contains($line, '|')) {
                continue;
            }

            [$title, $body] = array_map('trim', explode('|', $line, 2));

            if ($title === '' || $body === '') {
                continue;
            }

            $items[] = [
                'title' => mb_substr($title, 0, 60),
                'body' => mb_substr($body, 0, 2000),
            ];
        }

        return array_slice($items, 0, 12);
    }

    /**
     * create — общая запись обращения и первого сообщения.
     *
     * @param  array<string, mixed>  $data
     */
    protected function create(array $data): Ticket
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));

        $ticket = new Ticket();
        $ticket->fill([
            'customer_id' => !empty($data['customer_id']) ? (int) $data['customer_id'] : null,
            'email' => $email,
            'order_id' => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'subject' => mb_substr(trim((string) ($data['subject'] ?? '')), 0, 200),
            'status' => Ticket::STATUS_NEW,
            'priority' => Ticket::PRIORITY_NORMAL,
            'source' => (string) ($data['source'] ?? 'account'),
        ]);
        $ticket->save();

        $history = (array) ($data['history'] ?? []);

        if ($history === []) {
            $this->addMessage(
                $ticket,
                Ticket::AUTHOR_CUSTOMER,
                (string) ($data['body'] ?? ''),
                $name,
                (array) ($data['attachments'] ?? [])
            );
        }
        else {
            foreach ($history as $item) {
                $this->addMessage(
                    $ticket,
                    (string) ($item['author'] ?? Ticket::AUTHOR_CUSTOMER),
                    (string) ($item['body'] ?? ''),
                    (string) ($item['author'] ?? '') === Ticket::AUTHOR_BOT ? 'Консультант' : $name
                );
            }
        }

        $this->notifyAdmin($ticket);
        Telegram::notifyNew($ticket);

        return $ticket->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $attachments
     */
    protected function addMessage(Ticket $ticket, string $author, string $body, string $name = '', array $attachments = []): TicketMessage
    {
        return $ticket->messages()->create([
            'author' => $author,
            'author_name' => mb_substr($name, 0, 160),
            'body' => trim($body),
            'attachments' => $attachments !== [] ? array_values($attachments) : null,
        ]);
    }

    protected function notifyAdmin(Ticket $ticket): void
    {
        $admin = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        if ($admin === '') {
            Log::warning('Elv Kit: некуда отправить письмо о обращении', ['ticket' => $ticket->number]);

            return;
        }

        try {
            Mail::to($admin)->queue(new TicketAdmin($ticket));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо о обращении не отправлено', [
                'ticket' => $ticket->number,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function notifyCustomer(Ticket $ticket, TicketMessage $message): void
    {
        if (trim((string) $ticket->email) === '') {
            return;
        }

        try {
            Mail::to($ticket->email)->queue(new TicketCustomer($ticket, $message));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо с ответом поддержки не отправлено', [
                'ticket' => $ticket->number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
