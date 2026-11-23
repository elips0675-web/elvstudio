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
 */
class TicketService
{
    /**
     * open — новое обращение покупателя с первым сообщением.
     *
     * @param  array{subject: string, body: string, order_id?: int|null}  $data
     */
    public function open(Customer $customer, array $data): Ticket
    {
        $ticket = new Ticket();
        $ticket->fill([
            'customer_id' => (int) $customer->id,
            'email' => (string) $customer->email,
            'order_id' => !empty($data['order_id']) ? (int) $data['order_id'] : null,
            'subject' => (string) $data['subject'],
            'status' => Ticket::STATUS_NEW,
            'priority' => Ticket::PRIORITY_NORMAL,
            'source' => 'account',
        ]);
        $ticket->save();

        $this->addMessage($ticket, Ticket::AUTHOR_CUSTOMER, (string) $data['body'], $customer->display_name);
        $this->notifyAdmin($ticket);
        Telegram::notifyNew($ticket);

        return $ticket->fresh();
    }

    /**
     * customerReply — сообщение покупателя в существующем обращении.
     */
    public function customerReply(Ticket $ticket, string $body, string $name = ''): TicketMessage
    {
        $message = $this->addMessage($ticket, Ticket::AUTHOR_CUSTOMER, $body, $name);

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
    public function close(Ticket $ticket, string $authorName = 'Поддержка'): void
    {
        if ($ticket->is_closed) {
            return;
        }

        $ticket->status = Ticket::STATUS_CLOSED;
        $ticket->closed_at = now();
        $ticket->save();

        $this->addMessage($ticket, Ticket::AUTHOR_SYSTEM, 'Обращение закрыто.', $authorName);
    }

    protected function addMessage(Ticket $ticket, string $author, string $body, string $name = ''): TicketMessage
    {
        $body = trim($body);

        return $ticket->messages()->create([
            'author' => $author,
            'author_name' => mb_substr($name, 0, 160),
            'body' => $body,
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
