<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Message;
use October\Rain\Mail\Mailable;

/**
 * MessageAdmin — сообщение из виджета обратной связи.
 */
class MessageAdmin extends Mailable
{
    public function __construct(public Message $message)
    {
    }

    public function build()
    {
        return $this->subject('Обратная связь: ' . ($this->message->subject ?: 'без темы'))
            ->view('elv.kit::mail.message-admin')
            ->with([
                'message' => [
                    'name' => (string) $this->message->name,
                    'email' => (string) $this->message->email,
                    'phone' => (string) $this->message->phone,
                    'subject' => (string) $this->message->subject,
                    'body' => (string) $this->message->message,
                    'source' => (string) $this->message->source,
                ],
            ]);
    }
}
