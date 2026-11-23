<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Ticket;
use Elv\Kit\Models\TicketMessage;
use October\Rain\Mail\Mailable;

/**
 * TicketCustomer — письмо покупателю с ответом поддержки.
 */
class TicketCustomer extends Mailable
{
    public function __construct(public Ticket $ticket, public TicketMessage $message)
    {
    }

    public function build()
    {
        return $this->subject('Ответ по обращению ' . $this->ticket->number)
            ->view('elv.kit::mail.ticket-customer')
            ->with([
                'ticket' => [
                    'number' => (string) $this->ticket->number,
                    'subject' => (string) $this->ticket->subject,
                    'url' => (string) $this->ticket->url,
                    'body' => (string) $this->message->body,
                    'author' => (string) $this->message->author_name,
                ],
            ]);
    }
}
