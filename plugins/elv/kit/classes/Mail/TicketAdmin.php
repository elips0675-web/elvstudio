<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Classes\Support;
use Elv\Kit\Models\Ticket;
use October\Rain\Mail\Mailable;

/**
 * TicketAdmin — письмо поддержке о новом обращении или ответе покупателя.
 */
class TicketAdmin extends Mailable
{
    public function __construct(public Ticket $ticket)
    {
    }

    public function build()
    {
        $last = $this->ticket->messages->last();

        return $this->subject('Обращение ' . $this->ticket->number . ': ' . $this->ticket->subject)
            ->view('elv.kit::mail.ticket-admin')
            ->with([
                'ticket' => [
                    'number' => (string) $this->ticket->number,
                    'subject' => (string) $this->ticket->subject,
                    'email' => (string) $this->ticket->email,
                    'status' => (string) $this->ticket->status_label,
                    'sla' => Support::describe($this->ticket),
                    'url' => (string) $this->ticket->url,
                    'body' => $last ? (string) $last->body : '',
                ],
            ]);
    }
}
