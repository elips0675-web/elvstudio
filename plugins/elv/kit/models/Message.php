<?php namespace Elv\Kit\Models;

use Model;

/**
 * Message — сообщение виджета обратной связи.
 */
class Message extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public const STATUS_NEW = 'new';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';

    public $table = 'elv_kit_messages';

    public $rules = [
        'name' => 'required|max:160',
        'email' => 'required|email|max:191',
        'message' => 'required|max:5000',
    ];

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_IN_PROGRESS => 'В работе',
            self::STATUS_CLOSED => 'Закрыто',
            default => 'Новое',
        };
    }
}
