<?php namespace Elv\Kit\Models;

use Model;

/**
 * TicketMessage — сообщение в обращении.
 *
 * Автор хранится строкой, а не ссылкой на пользователя: покупатель входит по
 * ссылке без учётной записи, а сотрудник поддержки может смениться. Имя
 * сохраняется на момент отправки, чтобы история не переписывалась.
 */
class TicketMessage extends Model
{
    public $table = 'elv_kit_ticket_messages';

    public $fillable = [
        'ticket_id', 'author', 'author_name', 'body',
    ];

    public $rules = [
        'ticket_id' => 'required|integer',
        'author' => 'required|in:customer,support,system',
        'body' => 'required|max:5000',
    ];

    public $belongsTo = [
        'ticket' => Ticket::class,
    ];

    public function getAuthorLabelAttribute(): string
    {
        return match ((string) $this->author) {
            Ticket::AUTHOR_SUPPORT => 'Поддержка',
            Ticket::AUTHOR_SYSTEM => 'Система',
            default => 'Покупатель',
        };
    }

    public function getIsSupportAttribute(): bool
    {
        return (string) $this->author === Ticket::AUTHOR_SUPPORT;
    }
}
