<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\TicketFiles;
use Model;

/**
 * TicketMessage — сообщение в обращении.
 *
 * Автор хранится строкой, а не ссылкой на пользователя: покупатель входит по
 * ссылке без учётной записи, а сотрудник поддержки может смениться. Имя
 * сохраняется на момент отправки, чтобы история не переписывалась.
 *
 * Вложения лежат списком в `attachments`; файл на диске открывается только
 * через маршрут с проверкой прав, поэтому в шаблоне выводится не путь, а
 * ссылка и исходное имя.
 */
class TicketMessage extends Model
{
    public $table = 'elv_kit_ticket_messages';

    public $fillable = [
        'ticket_id', 'author', 'author_name', 'body', 'attachments',
    ];

    public $rules = [
        'ticket_id' => 'required|integer',
        'author' => 'required|in:customer,support,system,bot',
        'body' => 'required|max:5000',
    ];

    protected $casts = [
        'attachments' => 'array',
    ];

    public $belongsTo = [
        'ticket' => Ticket::class,
    ];

    public function getAuthorLabelAttribute(): string
    {
        return match ((string) $this->author) {
            Ticket::AUTHOR_SUPPORT => 'Поддержка',
            Ticket::AUTHOR_SYSTEM => 'Система',
            Ticket::AUTHOR_BOT => 'Консультант',
            default => 'Покупатель',
        };
    }

    public function getIsSupportAttribute(): bool
    {
        return (string) $this->author === Ticket::AUTHOR_SUPPORT;
    }

    public function getIsBotAttribute(): bool
    {
        return (string) $this->author === Ticket::AUTHOR_BOT;
    }

    /**
     * files — вложения сообщения с готовой ссылкой на скачивание.
     *
     * @return array<int, array{name: string, size: int, size_label: string, url: string}>
     */
    public function getFilesAttribute(): array
    {
        $files = [];

        foreach ((array) $this->attachments as $item) {
            if (!is_array($item) || empty($item['file'])) {
                continue;
            }

            $files[] = [
                'name' => (string) ($item['name'] ?? $item['file']),
                'size' => (int) ($item['size'] ?? 0),
                'size_label' => TicketFiles::sizeLabel((int) ($item['size'] ?? 0)),
                'url' => url('/tickets') . '/' . (int) $this->ticket_id . '/file/' . rawurlencode((string) $item['file']),
            ];
        }

        return $files;
    }
}
